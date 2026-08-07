<?php

use Fleetbase\Ledger\Models\GatewayTransaction;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Notifications\InvoiceSent;
use Fleetbase\Ledger\Notifications\RefundUriAvailable;
use Fleetbase\Models\Company;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Collection;

final class InvoiceSentProbe extends InvoiceSent
{
    public function useCompany(?Company $company): void
    {
        $this->company = $company;
    }

    public function lookupCompany(): ?Company
    {
        return $this->company();
    }
}

final class RefundUriAvailableProbe extends RefundUriAvailable
{
    public function useCompany(?Company $company): void
    {
        $this->company = $company;
    }

    public function lookupCompany(): ?Company
    {
        return $this->company();
    }
}

final class NotificationInvoiceProbe extends Invoice
{
    public function loadMissing($relations)
    {
        return $this;
    }
}

function notificationInvoice(array $attributes = [], mixed $customer = null, mixed $order = null, array $items = []): Invoice
{
    $invoice = new NotificationInvoiceProbe();
    $invoice->setRawAttributes(array_merge([
        'public_id'    => 'invoice_public_1',
        'company_uuid' => 'company-1',
        'number'       => 'INV-1001',
        'currency'     => 'usd',
        'date'         => '2026-07-01',
        'due_date'     => '2026-07-31',
        'subtotal'     => 1250,
        'tax'          => 125,
        'total_amount' => 1375,
        'amount_paid'  => 375,
        'balance'      => 1000,
    ], $attributes), true);
    $invoice->setRelation('customer', $customer);
    $invoice->setRelation('order', $order);
    $invoice->setRelation('items', new Collection($items));

    return $invoice;
}

beforeEach(function () {
    $capsule = new Capsule(Container::getInstance());
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'testing');
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'mysql');
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('testing');
    Container::getInstance()->instance('db', $capsule->getDatabaseManager());

    Capsule::schema('mysql')->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('name');
        $table->timestamps();
        $table->softDeletes();
    });
    Capsule::connection('mysql')->table('companies')->insert([
        'uuid'       => 'company-1',
        'name'       => 'Database Merchant',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

test('invoice sent mail renders the complete customer invoice and line item contract', function () {
    $company = new Company();
    $company->setRawAttributes(['name' => 'Acme Logistics', 'logo_url' => 'https://img.test/logo.png'], true);
    $customer = (object) ['name' => 'Ada Customer', 'email' => 'ada@example.test'];
    $order    = (object) ['tracking_number' => 'TRK-101'];
    $invoice  = notificationInvoice([], $customer, $order, [
        (object) ['description' => str_repeat('Long service ', 20), 'quantity' => 2, 'unit_price' => 500, 'amount' => 1000],
        (object) ['description' => null, 'quantity' => 0.5, 'unit_price' => null, 'amount' => 250],
    ]);
    $notification = new InvoiceSentProbe($invoice);
    $notification->useCompany($company);

    $mail = $notification->toMail((object) []);

    expect($notification->via((object) []))->toBe(['mail'])
        ->and($mail->subject)->toBe('Invoice INV-1001 from Acme Logistics')
        ->and($mail->view)->toBe('ledger::mail.invoice-sent')
        ->and($mail->viewData['companyLogoUrl'])->toContain('image-file-icon.png')
        ->and($mail->viewData['customerName'])->toBe('Ada Customer')
        ->and($mail->viewData['customerEmail'])->toBe('ada@example.test')
        ->and($mail->viewData['invoiceDate'])->toBe('Jul 1, 2026')
        ->and($mail->viewData['dueDate'])->toBe('Jul 31, 2026')
        ->and($mail->viewData['orderLabel'])->toBe('TRK-101')
        ->and($mail->viewData['subtotal'])->toBe('USD 12.50')
        ->and($mail->viewData['total'])->toBe('USD 13.75')
        ->and($mail->viewData['hasAmountPaid'])->toBeTrue()
        ->and($mail->viewData['items'][0]['quantity'])->toBe('2')
        ->and(strlen($mail->viewData['items'][0]['description']))->toBeLessThanOrEqual(143)
        ->and($mail->viewData['items'][1]['description'])->toBe('Invoice item')
        ->and($mail->viewData['items'][1]['quantity'])->toBe('0.5')
        ->and($mail->viewData['items'][1]['unitPrice'])->toBe('USD 5.00')
        ->and($mail->viewData['invoiceUrl'])->toContain('invoice_public_1');
});

test('invoice sent mail provides safe fallbacks for sparse invoice relationships', function () {
    $tracking = (object) ['tracking_number' => 'TRK-NESTED'];
    $order    = (object) ['tracking_number' => null, 'trackingNumber' => $tracking, 'public_id' => 'order-1', 'uuid' => 'order-uuid'];
    $customer = (object) ['display_name' => 'Fallback Customer', 'contact_email' => 'contact@example.test'];
    $invoice  = notificationInvoice([
        'number'      => null,
        'currency'    => null,
        'date'        => null,
        'due_date'    => null,
        'amount_paid' => 0,
    ], $customer, $order);
    $notification = new InvoiceSentProbe($invoice);
    $notification->useCompany(new Company());

    $mail = $notification->toMail((object) []);

    expect($mail->subject)->toBe('Invoice invoice_public_1 from Your service provider')
        ->and($mail->viewData['customerName'])->toBe('Fallback Customer')
        ->and($mail->viewData['customerEmail'])->toBe('contact@example.test')
        ->and($mail->viewData['orderLabel'])->toBe('TRK-NESTED')
        ->and($mail->viewData['items'])->toBe([])
        ->and($mail->viewData['invoiceDate'])->toBeNull()
        ->and($mail->viewData['dueDate'])->toBeNull()
        ->and($mail->viewData['hasAmountPaid'])->toBeFalse()
        ->and($mail->viewData['balance'])->toBe('USD 10.00');

    $invoice->setRelation('order', null);
    $mailWithoutOrder = $notification->toMail((object) []);
    expect($mailWithoutOrder->viewData['orderLabel'])->toBeNull();
});

test('refund URI mail renders provider handoff and customer context', function () {
    $company = new Company();
    $company->setRawAttributes(['name' => 'Refund Merchant', 'logo_url' => 'logo.svg'], true);
    $customer = (object) ['email' => 'refund@example.test'];
    $order    = (object) ['tracking_number' => null, 'trackingNumber' => null, 'public_id' => 'order-public'];
    $invoice  = notificationInvoice([], $customer, $order);
    $refund   = new GatewayTransaction();
    $refund->setRawAttributes([
        'public_id'  => 'refund-public',
        'uuid'       => 'refund-uuid',
        'amount'     => 425,
        'currency'   => 'eur',
        'created_at' => '2026-07-15 13:45:00',
    ], true);
    $notification = new RefundUriAvailableProbe($invoice, $refund, 'taler://refund/example');
    $notification->useCompany($company);

    $mail = $notification->toMail((object) []);

    expect($notification->via((object) []))->toBe(['mail'])
        ->and($mail->subject)->toBe('Refund available for invoice INV-1001')
        ->and($mail->view)->toBe('ledger::mail.refund-uri-available')
        ->and($mail->viewData['companyName'])->toBe('Refund Merchant')
        ->and($mail->viewData['customerName'])->toBe('refund@example.test')
        ->and($mail->viewData['orderLabel'])->toBe('order-public')
        ->and($mail->viewData['refundAmount'])->toBe('EUR 4.25')
        ->and($mail->viewData['refundUri'])->toBe('taler://refund/example')
        ->and($mail->viewData['refundUrl'])->toContain('refund-public')
        ->and($mail->viewData['invoiceUrl'])->toContain('invoice_public_1')
        ->and($mail->viewData['issuedAt'])->toBe('Jul 15, 2026 13:45');
});

test('refund URI mail falls back across missing company order currency and identifiers', function () {
    $invoice = notificationInvoice(['number' => null, 'currency' => 'mnt'], null, null);
    $refund  = new GatewayTransaction();
    $refund->setRawAttributes([
        'public_id'  => null,
        'uuid'       => 'refund-uuid-only',
        'amount'     => 1000,
        'currency'   => null,
        'created_at' => null,
    ], true);
    $notification = new RefundUriAvailableProbe($invoice, $refund, 'taler://refund/fallback');
    $notification->useCompany(new Company());

    $mail = $notification->toMail((object) []);

    expect($mail->subject)->toBe('Refund available for invoice invoice_public_1')
        ->and($mail->viewData['companyName'])->toBe('Your service provider')
        ->and($mail->viewData['companyLogoUrl'])->toContain('image-file-icon.png')
        ->and($mail->viewData['customerName'])->toBeNull()
        ->and($mail->viewData['orderLabel'])->toBeNull()
        ->and($mail->viewData['refundAmount'])->toBe('MNT 10.00')
        ->and($mail->viewData['refundUrl'])->toContain('refund-uuid-only')
        ->and($mail->viewData['issuedAt'])->toBeNull();
});

test('ledger notifications resolve and cache the invoice company when not preloaded', function () {
    $invoice = notificationInvoice();
    $refund  = new GatewayTransaction();
    $refund->setRawAttributes(['uuid' => 'refund-1'], true);

    $invoiceNotification = new InvoiceSentProbe($invoice);
    $refundNotification  = new RefundUriAvailableProbe($invoice, $refund, 'taler://refund/cache');

    expect($invoiceNotification->lookupCompany()?->name)->toBe('Database Merchant')
        ->and($invoiceNotification->lookupCompany()?->uuid)->toBe('company-1')
        ->and($refundNotification->lookupCompany()?->name)->toBe('Database Merchant')
        ->and($refundNotification->lookupCompany()?->uuid)->toBe('company-1');
});
