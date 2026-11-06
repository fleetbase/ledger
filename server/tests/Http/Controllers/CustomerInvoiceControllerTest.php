<?php

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Ledger\Http\Controllers\Api\v1\CustomerInvoiceController;
use Fleetbase\Ledger\Models\Invoice;

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

function customerInvoiceProbe(string $orderCustomer = 'rider-1'): CustomerInvoiceControllerProbe
{
    $probe           = new CustomerInvoiceControllerProbe();
    $probe->customer = new Contact();
    $probe->customer->setRawAttributes(['uuid' => 'rider-1'], true);
    $probe->order = new Order();
    $probe->order->setRawAttributes(['uuid' => 'order-1', 'company_uuid' => 'company-1', 'customer_uuid' => $orderCustomer], true);

    return $probe;
}

test('a rider gets the invoice for their own order', function () {
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

test('another rider and a missing order are both not found', function () {
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
