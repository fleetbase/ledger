<?php

use Fleetbase\Ledger\DTO\GatewayResponse;
use Fleetbase\Ledger\DTO\PurchaseRequest;
use Fleetbase\Ledger\DTO\RefundRequest;
use Fleetbase\Ledger\Events\InvoiceCreated;
use Fleetbase\Ledger\Events\InvoicePaid;
use Fleetbase\Ledger\Events\PaymentFailed;
use Fleetbase\Ledger\Events\PaymentSucceeded;
use Fleetbase\Ledger\Events\RefundProcessed;
use Fleetbase\Ledger\Exceptions\WebhookSignatureException;
use Fleetbase\Ledger\Gateways\CashDriver;
use Fleetbase\Ledger\Models\Gateway;
use Fleetbase\Ledger\Models\GatewayTransaction;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\PaymentGatewayManager;
use Illuminate\Container\Container;
use Illuminate\Http\Request;

test('gateway responses expose normalized success pending and failure contracts', function () {
    $success = GatewayResponse::success(
        gatewayTransactionId: 'payment-1',
        message: 'captured',
        amount: 1250,
        currency: 'USD',
        rawResponse: ['provider' => 'ok'],
        data: ['receipt' => 'receipt-1'],
    );
    $pending = GatewayResponse::pending(
        gatewayTransactionId: 'payment-2',
        message: 'awaiting confirmation',
        rawResponse: ['provider' => 'pending'],
        data: ['checkout_url' => 'https://pay.example.test'],
    );
    $failure = GatewayResponse::failure(
        gatewayTransactionId: 'payment-3',
        eventType: GatewayResponse::EVENT_REFUND_FAILED,
        message: 'declined',
        errorCode: 'card_declined',
        rawResponse: ['decline_code' => 'insufficient_funds'],
    );

    expect($success->isSuccessful())->toBeTrue()
        ->and($success->isFailed())->toBeFalse()
        ->and($success->isPending())->toBeFalse()
        ->and($success->status)->toBe(GatewayResponse::STATUS_SUCCEEDED)
        ->and($success->amount)->toBe(1250)
        ->and($success->currency)->toBe('USD')
        ->and($success->rawResponse)->toBe(['provider' => 'ok'])
        ->and($success->data)->toBe(['receipt' => 'receipt-1'])
        ->and($pending->isSuccessful())->toBeTrue()
        ->and($pending->isPending())->toBeTrue()
        ->and($pending->eventType)->toBe(GatewayResponse::EVENT_PAYMENT_PENDING)
        ->and($pending->data['checkout_url'])->toBe('https://pay.example.test')
        ->and($failure->isFailed())->toBeTrue()
        ->and($failure->status)->toBe(GatewayResponse::STATUS_FAILED)
        ->and($failure->eventType)->toBe(GatewayResponse::EVENT_REFUND_FAILED)
        ->and($failure->errorCode)->toBe('card_declined');
});

test('purchase and refund requests retain immutable gateway input', function () {
    $purchase = new PurchaseRequest(
        amount: 10050,
        currency: 'USD',
        description: 'Invoice INV-100',
        paymentMethodToken: 'pm_1',
        customerId: 'cus_1',
        customerEmail: 'customer@example.test',
        invoiceUuid: 'invoice-uuid',
        orderUuid: 'order-uuid',
        returnUrl: 'https://example.test/return',
        cancelUrl: 'https://example.test/cancel',
        metadata: ['source' => 'ledger'],
    );
    $refund = new RefundRequest(
        gatewayTransactionId: 'payment-1',
        amount: 5050,
        currency: 'USD',
        reason: 'requested_by_customer',
        invoiceUuid: 'invoice-uuid',
        metadata: ['operator' => 'user-1'],
    );

    expect($purchase->getFormattedAmount())->toBe('100.50')
        ->and($purchase->getFormattedAmount(0))->toBe('10,050')
        ->and($purchase->paymentMethodToken)->toBe('pm_1')
        ->and($purchase->metadata)->toBe(['source' => 'ledger'])
        ->and($refund->gatewayTransactionId)->toBe('payment-1')
        ->and($refund->amount)->toBe(5050)
        ->and($refund->reason)->toBe('requested_by_customer')
        ->and($refund->metadata)->toBe(['operator' => 'user-1']);
});

test('cash driver records purchases and refunds without an external provider', function () {
    $driver = (new CashDriver())->initialize([
        'label'        => 'Pay at counter',
        'instructions' => 'Collect a stamped receipt.',
    ], true);

    $purchase = $driver->purchase(new PurchaseRequest(
        amount: 2250,
        currency: 'USD',
        description: 'Counter payment',
    ));
    $refund = $driver->refund(new RefundRequest(
        gatewayTransactionId: $purchase->gatewayTransactionId,
        amount: 750,
        currency: 'USD',
        reason: 'requested_by_customer',
    ));

    expect($driver->getCode())->toBe('cash')
        ->and($driver->getName())->toBe('Cash / Manual')
        ->and($driver->getCapabilities())->toBe(['purchase', 'refund'])
        ->and($driver->hasCapability('purchase'))->toBeTrue()
        ->and($driver->hasCapability('webhooks'))->toBeFalse()
        ->and(array_column($driver->getConfigSchema(), 'key'))->toBe(['label', 'instructions'])
        ->and($purchase->gatewayTransactionId)->toStartWith('cash_')
        ->and($purchase->amount)->toBe(2250)
        ->and($purchase->message)->toBe('Collect a stamped receipt.')
        ->and($purchase->data['label'])->toBe('Pay at counter')
        ->and($refund->gatewayTransactionId)->toStartWith('cash_refund_')
        ->and($refund->eventType)->toBe(GatewayResponse::EVENT_REFUND_PROCESSED)
        ->and($refund->data['original_reference_id'])->toBe($purchase->gatewayTransactionId);
});

test('cash driver supplies safe customer defaults', function () {
    $response = (new CashDriver())->initialize([])->purchase(new PurchaseRequest(
        amount: 500,
        currency: 'MNT',
        description: 'Manual payment',
    ));

    expect($response->message)->toBe('Cash payment recorded. Collect payment manually.')
        ->and($response->data['label'])->toBe('Cash / Manual');
});

test('base gateway behavior rejects unsupported tokenization and webhooks', function () {
    $driver = new CashDriver();

    expect(fn () => $driver->createPaymentMethod([]))
        ->toThrow(RuntimeException::class, 'Gateway [cash] does not support payment method tokenization');

    $response = $driver->handleWebhook(Request::create('/webhook', 'POST'));

    expect($response->isFailed())->toBeTrue()
        ->and($response->eventType)->toBe(GatewayResponse::EVENT_UNKNOWN)
        ->and($response->message)->toBe('Gateway [cash] does not support webhooks.');
});

test('gateway manager resolves built in drivers and publishes their manifest', function () {
    $container = Container::getInstance();
    $manager   = new PaymentGatewayManager($container);

    expect($manager->getRegisteredDriverCodes())->toBe(['stripe', 'qpay', 'cash', 'taler'])
        ->and($manager->getDefaultDriver())->toBe('cash')
        ->and($manager->driver('cash'))->toBeInstanceOf(CashDriver::class);

    $manifest = collect($manager->getDriverManifest())->keyBy('code');

    expect($manifest->keys()->all())->toBe(['stripe', 'qpay', 'cash', 'taler'])
        ->and($manifest['cash']['name'])->toBe('Cash / Manual')
        ->and($manifest['cash']['capabilities'])->toBe(['purchase', 'refund'])
        ->and($manifest['cash']['webhook_url'])->toContain('/ledger/webhooks/cash');
});

test('gateway manager skips a driver that cannot be instantiated in its manifest', function () {
    $manager = new class(Container::getInstance()) extends PaymentGatewayManager {
        public function getRegisteredDriverCodes(): array
        {
            return ['cash', 'missing'];
        }
    };

    expect($manager->getDriverManifest())->toHaveCount(1)
        ->and($manager->getDriverManifest()[0]['code'])->toBe('cash');
});

test('webhook signature exceptions identify the gateway and optional reason', function () {
    expect((new WebhookSignatureException('stripe'))->getMessage())
        ->toBe('Webhook signature verification failed for gateway [stripe].')
        ->and((new WebhookSignatureException('qpay', 'Timestamp expired.'))->getMessage())
        ->toBe('Webhook signature verification failed for gateway [qpay]. Timestamp expired.');
});

test('ledger domain events retain the exact payment aggregates', function () {
    $invoice            = new Invoice();
    $gateway            = new Gateway();
    $gatewayTransaction = new GatewayTransaction();
    $response           = GatewayResponse::success('payment-1');

    expect((new InvoiceCreated($invoice))->invoice)->toBe($invoice)
        ->and((new InvoicePaid($invoice))->invoice)->toBe($invoice);

    foreach ([
        new PaymentSucceeded($response, $gateway, $gatewayTransaction),
        new PaymentFailed($response, $gateway, $gatewayTransaction),
        new RefundProcessed($response, $gateway, $gatewayTransaction),
    ] as $event) {
        expect($event->response)->toBe($response)
            ->and($event->gateway)->toBe($gateway)
            ->and($event->gatewayTransaction)->toBe($gatewayTransaction);
    }
});
