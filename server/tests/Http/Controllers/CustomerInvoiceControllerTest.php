<?php

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\CustomerAuth;
use Fleetbase\Ledger\Http\Controllers\Api\v1\CustomerInvoiceController;
use Fleetbase\Ledger\Models\Invoice;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

class CustomerInvoiceControllerProbe extends CustomerInvoiceController
{
    public ?Contact $customer = null;
    public ?Order $order      = null;
    public ?Invoice $invoice  = null;

    protected function currentCustomer(): ?Contact
    {
        return $this->customer;
    }

    protected function findOrder(string $id): ?Order
    {
        return $this->order;
    }

    protected function findInvoice(Order $order): ?Invoice
    {
        return $this->invoice;
    }

    protected function paymentUrl(Invoice $invoice): string
    {
        return 'https://console.test/~/invoice?id=' . $invoice->public_id;
    }
}

function customerInvoiceProbe(string $orderCustomer = 'customer-1'): CustomerInvoiceControllerProbe
{
    $probe           = new CustomerInvoiceControllerProbe();
    $probe->customer = new Contact();
    $probe->customer->setRawAttributes(['uuid' => 'customer-1'], true);
    $probe->order = new Order();
    $probe->order->setRawAttributes(['uuid' => 'order-1', 'company_uuid' => 'company-1', 'customer_uuid' => $orderCustomer], true);

    return $probe;
}

test('a customer gets the invoice for their own order', function () {
    $probe          = customerInvoiceProbe();
    $probe->invoice = new Invoice();
    $probe->invoice->setRawAttributes(['public_id' => 'invoice_1', 'status' => 'sent', 'total_amount' => 2401, 'balance' => 2401, 'currency' => 'SGD'], true);

    expect($probe->forOrder('order_1')->getData(true))->toBe(['invoice' => [
        'id'           => 'invoice_1',
        'status'       => 'sent',
        'total_amount' => 2401,
        'balance'      => 2401,
        'currency'     => 'SGD',
        'payment_url'  => 'https://console.test/~/invoice?id=invoice_1',
    ]]);
});

test('an order with no invoice yet answers with none', function () {
    expect(customerInvoiceProbe()->forOrder('order_1')->getData(true))->toBe(['invoice' => null]);
});

test('another customer\'s order and a missing order are both not found', function () {
    $missing        = customerInvoiceProbe();
    $missing->order = null;

    expect(customerInvoiceProbe('someone-else')->forOrder('order_1')->getStatusCode())->toBe(404)
        ->and($missing->forOrder('order_1')->getStatusCode())->toBe(404);
});

test('the payment page is the hosted invoice page', function () {
    config()->set('fleetbase.console.host', 'https://console.test');
    $invoice = new Invoice();
    $invoice->setRawAttributes(['public_id' => 'invoice_1'], true);
    $method = new ReflectionMethod(CustomerInvoiceController::class, 'paymentUrl');

    expect($method->invoke(new CustomerInvoiceController(), $invoice))->toContain('~/invoice?id=invoice_1');
});

/**
 * Real orders and invoices, the customer bound the way the customer-token middleware binds
 * them, and the console host configured, so none of the controller's seams are replaced.
 */
function bootCustomerInvoiceControllerDatabase(): void
{
    $capsule    = new Capsule(Container::getInstance());
    $dispatcher = new Dispatcher(Container::getInstance());
    $capsule->setEventDispatcher($dispatcher);
    Model::setEventDispatcher($dispatcher);
    Model::clearBootedModels();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'testing');
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('testing');
    Container::getInstance()->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstance('db');
    config()->set('fleetbase.console.host', 'https://console.test');
    session(['company' => 'company-1']);

    $schema = $capsule->getConnection('testing')->getSchemaBuilder();
    $schema->create('orders', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('customer_uuid')->nullable();
        $table->string('tracking_number_uuid')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('ledger_invoices', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('order_uuid')->nullable();
        $table->string('status');
        $table->bigInteger('total_amount')->default(0);
        $table->bigInteger('balance')->default(0);
        $table->string('currency')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('ledger_invoice_items', function (Blueprint $table) {
        $table->increments('id');
        $table->string('invoice_uuid');
        $table->softDeletes();
    });
    // Invoices eager-load their order's tracking number and their template.
    foreach (['tracking_numbers', 'templates'] as $tableName) {
        $schema->create($tableName, function (Blueprint $table) {
            $table->string('uuid')->primary();
            $table->softDeletes();
        });
    }

    Capsule::table('orders')->insert([
        ['uuid' => 'order-1', 'public_id' => 'order_1', 'company_uuid' => 'company-1', 'customer_uuid' => 'customer-1'],
        ['uuid' => 'order-other-company', 'public_id' => 'order_elsewhere', 'company_uuid' => 'company-2', 'customer_uuid' => 'customer-1'],
        ['uuid' => 'order-2', 'public_id' => 'order_2', 'company_uuid' => 'company-1', 'customer_uuid' => 'customer-1'],
    ]);
    $invoice = fn (string $uuid, string $order, string $status, string $createdAt, string $company = 'company-1') => [
        'uuid'         => $uuid,
        'public_id'    => str_replace('-', '_', $uuid),
        'company_uuid' => $company,
        'order_uuid'   => $order,
        'status'       => $status,
        'total_amount' => 2401,
        'balance'      => 1200,
        'currency'     => 'SGD',
        'created_at'   => $createdAt,
        'updated_at'   => $createdAt,
    ];
    Capsule::table('ledger_invoices')->insert([
        $invoice('invoice-older-sent', 'order-1', 'sent', '2026-01-01 00:00:00'),
        $invoice('invoice-partial', 'order-1', 'partial', '2026-01-02 00:00:00'),
        $invoice('invoice-draft', 'order-1', 'draft', '2026-01-03 00:00:00'),
        $invoice('invoice-void', 'order-1', 'void', '2026-01-04 00:00:00'),
        $invoice('invoice-cancelled', 'order-1', 'cancelled', '2026-01-05 00:00:00'),
        $invoice('invoice-canceled', 'order-1', 'canceled', '2026-01-06 00:00:00'),
        $invoice('invoice-other-company', 'order-1', 'sent', '2026-01-07 00:00:00', 'company-2'),
        $invoice('invoice-order-2-draft', 'order-2', 'draft', '2026-01-01 00:00:00'),
    ]);
}

function signInCustomerInvoiceCustomer(string $uuid): void
{
    $contact = new Contact();
    $contact->setRawAttributes(['uuid' => $uuid, 'type' => 'customer'], true);
    CustomerAuth::setCurrent($contact);
}

afterEach(function () {
    Container::getInstance()->forgetInstance(CustomerAuth::APP_BINDING);
});

test('the signed-in customer gets the newest invoice they can see for their order, with its payment page', function () {
    bootCustomerInvoiceControllerDatabase();
    signInCustomerInvoiceCustomer('customer-1');

    expect((new CustomerInvoiceController())->forOrder('order_1')->getData(true))->toBe(['invoice' => [
        'id'           => 'invoice_partial',
        'status'       => 'partial',
        'total_amount' => 2401,
        'balance'      => 1200,
        'currency'     => 'SGD',
        'payment_url'  => 'https://console.test/~/invoice?id=invoice_partial',
    ]]);
});

test('an order whose only invoice is a draft answers with none', function () {
    bootCustomerInvoiceControllerDatabase();
    signInCustomerInvoiceCustomer('customer-1');

    expect((new CustomerInvoiceController())->forOrder('order_2')->getData(true))->toBe(['invoice' => null]);
});

test('orders of another company or customer, missing orders and signed-out requests are not found', function () {
    bootCustomerInvoiceControllerDatabase();
    $controller = new CustomerInvoiceController();

    expect($controller->forOrder('order_1')->getStatusCode())->toBe(404);

    signInCustomerInvoiceCustomer('customer-2');
    expect($controller->forOrder('order_1')->getStatusCode())->toBe(404);

    signInCustomerInvoiceCustomer('customer-1');
    expect($controller->forOrder('order_elsewhere')->getStatusCode())->toBe(404)
        ->and($controller->forOrder('order_missing')->getStatusCode())->toBe(404)
        ->and($controller->forOrder('order_1')->getStatusCode())->toBe(200);
});
