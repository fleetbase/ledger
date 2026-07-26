<?php

use Fleetbase\Ledger\DTO\GatewayResponse;
use Fleetbase\Ledger\DTO\PurchaseRequest;
use Fleetbase\Ledger\Gateways\CashDriver;
use Fleetbase\Ledger\Gateways\StripeDriver;
use Fleetbase\Ledger\Http\Controllers\Public\PublicInvoiceController;
use Fleetbase\Ledger\PaymentGatewayManager;
use Fleetbase\Ledger\Services\PaymentService;
use Illuminate\Container\Container;
use Illuminate\Http\Request;

class PublicInvoiceGatewayManager extends PaymentGatewayManager
{
    public function __construct(public mixed $testDriver)
    {
    }

    public function driver($driver = null)
    {
        return $this->testDriver;
    }

    public function getDefaultDriver(): string
    {
        return 'cash';
    }
}

class PublicInvoiceAsyncDriver
{
    public array $calls = [];

    public function initialize(array $config, bool $sandbox = false): static
    {
        $this->calls[] = [$config, $sandbox];

        return $this;
    }
}

class PublicInvoiceStripeDriver extends StripeDriver
{
    public array $calls = [];
    public GatewayResponse|Throwable $result;

    public function initialize(array $config, bool $sandbox = false): static
    {
        $this->calls[] = ['initialize', $config, $sandbox];

        return $this;
    }

    public function createCheckoutSession(PurchaseRequest $request, string $successUrl, string $cancelUrl): GatewayResponse
    {
        $this->calls[] = ['checkout', $request, $successUrl, $cancelUrl];

        if ($this->result instanceof Throwable) {
            throw $this->result;
        }

        return $this->result;
    }
}

class PublicInvoicePaymentService extends PaymentService
{
    public array $calls = [];
    public GatewayResponse $response;

    public function __construct()
    {
    }

    public function charge(string $gatewayIdentifier, PurchaseRequest $request): GatewayResponse
    {
        $this->calls[] = [$gatewayIdentifier, $request];

        return $this->response;
    }
}

class PublicInvoiceQrGenerator
{
    public function __construct(public bool $throw = false)
    {
    }

    public function getBarcodePNG(string $value, string $type, int $width, int $height): string
    {
        if ($this->throw) {
            throw new RuntimeException('QR generation unavailable');
        }

        return base64_encode($value . ':' . $type . ':' . $width . ':' . $height);
    }
}

function bootPublicInvoiceControllerDatabase(): void
{
    bootInvoiceControllerDatabase();
    $schema = Illuminate\Database\Capsule\Manager::schema('testing');
    $schema->create('companies', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
}

function publicInvoiceController(
    mixed $driver,
    ?InvoiceControllerServiceSpy $invoiceService = null,
    ?PublicInvoicePaymentService $paymentService = null,
): PublicInvoiceController {
    return new PublicInvoiceController(
        $invoiceService ?? new InvoiceControllerServiceSpy(),
        new PublicInvoiceGatewayManager($driver),
        $paymentService ?? new PublicInvoicePaymentService(),
    );
}

beforeEach(function () {
    bootPublicInvoiceControllerDatabase();
    Container::getInstance()->instance('request', Request::create('/ledger/public/invoices'));
});

test('public invoice display blocks drafts and advances sent invoices to viewed once', function () {
    $controller = publicInvoiceController(new PublicInvoiceAsyncDriver());
    $draft      = invoiceControllerInvoice(['status' => 'draft']);

    expect($controller->show($draft->public_id)->getStatusCode())->toBe(403);

    $sent  = invoiceControllerInvoice(['status' => 'sent', 'viewed_at' => null]);
    $first = $controller->show($sent->public_id);
    $sent->refresh();
    expect($first->getStatusCode())->toBe(200)
        ->and($sent->status)->toBe('viewed')
        ->and($sent->viewed_at)->not->toBeNull();

    $viewedAt = $sent->viewed_at->toISOString();
    $controller->show($sent->uuid);
    expect($sent->fresh()->viewed_at->toISOString())->toBe($viewedAt);
});

test('public gateway catalog exposes only active gateways for the invoice company', function () {
    $invoice = invoiceControllerInvoice(['status' => 'sent']);
    invoiceControllerGateway('cash');
    $inactive         = invoiceControllerGateway('taler');
    $inactive->status = 'inactive';
    $inactive->save();
    Container::getInstance()->instance('request', Request::create('/ledger/public/invoices'));

    $data = publicInvoiceController(new PublicInvoiceAsyncDriver())
        ->gateways($invoice->public_id)
        ->getData(true);

    expect($data['gateways'])->toHaveCount(1)
        ->and($data['gateways'][0]['driver'])->toBe('cash')
        ->and($data['gateways'][0])->not->toHaveKey('config');
});

test('public payments reject terminal invoices exhausted balances and unavailable gateways', function () {
    $controller = publicInvoiceController(new PublicInvoiceAsyncDriver());
    foreach (['paid', 'refunded', 'refund_pending', 'partial_refund_pending', 'void', 'cancelled'] as $status) {
        $invoice = invoiceControllerInvoice([
            'status'       => $status,
            'total_amount' => 1000,
            'amount_paid'  => $status === 'paid' ? 1000 : 0,
            'balance'      => $status === 'paid' ? 0 : 1000,
        ]);
        expect($controller->pay(invoiceControllerRequest(['gateway_id' => 'missing']), $invoice->uuid)->getStatusCode())
            ->toBe(422);
    }
    $zero = invoiceControllerInvoice(['status' => 'sent', 'balance' => 0]);
    expect($controller->pay(invoiceControllerRequest(['gateway_id' => 'missing']), $zero->uuid)->getStatusCode())->toBe(422);

    $open    = invoiceControllerInvoice(['status' => 'sent', 'balance' => 500, 'total_amount' => 500]);
    $missing = $controller->pay(invoiceControllerRequest(['gateway_id' => 'missing']), $open->public_id);
    expect($missing->getStatusCode())->toBe(422)
        ->and($missing->getData(true)['error'])->toContain('not found');
});

test('cash public payments record the exact invoice balance immediately', function () {
    $gateway = invoiceControllerGateway('cash');
    $invoice = invoiceControllerInvoice([
        'status'       => 'sent',
        'total_amount' => 750,
        'amount_paid'  => 0,
        'balance'      => 750,
    ]);
    $invoiceService = new InvoiceControllerServiceSpy();
    $driver         = new CashDriver();
    $controller     = publicInvoiceController($driver, $invoiceService);

    $response = $controller->pay(invoiceControllerRequest([
        'gateway_id' => $gateway->public_id,
        'reference'  => 'Paid at counter',
    ]), $invoice->public_id);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['message'])->toBe('Payment recorded successfully.')
        ->and($invoiceService->calls[0])->toBe([
            'recordPayment',
            $invoice->uuid,
            750,
            ['payment_method' => 'cash', 'reference' => 'Paid at counter'],
        ]);
});

test('asynchronous public payments construct metadata and map failure pending and success states', function () {
    $gateway = invoiceControllerGateway('taler');
    Illuminate\Database\Capsule\Manager::table('customers')->insert([
        'uuid'       => 'public-payment-customer',
        'name'       => 'Public Customer',
        'email'      => 'payer@example.test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $invoice = invoiceControllerInvoice([
        'customer_uuid' => 'public-payment-customer',
        'customer_type' => Fleetbase\Models\Customer::class,
        'status'        => 'sent',
        'number'        => 'INV-PUBLIC',
        'total_amount'  => 1000,
        'balance'       => 1000,
    ]);
    $payment    = new PublicInvoicePaymentService();
    $driver     = new PublicInvoiceAsyncDriver();
    $controller = publicInvoiceController($driver, null, $payment);

    $payment->response = GatewayResponse::failure(message: null);
    $failed            = $controller->pay(invoiceControllerRequest(['gateway_id' => $gateway->uuid]), $invoice->uuid);
    expect($failed->getStatusCode())->toBe(422)
        ->and($failed->getData(true)['error'])->toContain('Failed to initiate');

    $payment->response = GatewayResponse::pending(
        'taler-order',
        message: 'Scan to pay',
        data: ['taler_pay_uri' => 'taler://pay/order', 'qr_image' => 'image-data'],
    );
    $pending      = $controller->pay(invoiceControllerRequest(['gateway_id' => $gateway->public_id]), $invoice->uuid);
    [, $purchase] = $payment->calls[1];
    expect($pending->getData(true))->toMatchArray([
        'status'      => 'pending',
        'payment_url' => 'taler://pay/order',
        'payment_uri' => 'taler://pay/order',
        'qr_text'     => 'taler://pay/order',
    ])->and($pending->getData(true)['gateway']['driver'])->toBe('taler')
        ->and($purchase->amount)->toBe(1000)
        ->and($purchase->customerEmail)->toBe('payer@example.test')
        ->and($purchase->metadata)->toMatchArray([
            'invoice_public_id' => $invoice->public_id,
            'gateway_public_id' => $gateway->public_id,
            'gateway_driver'    => 'taler',
        ]);

    $payment->response = GatewayResponse::success('instant-payment', message: null);
    $success           = $controller->pay(invoiceControllerRequest(['gateway_id' => $gateway->uuid]), $invoice->uuid);
    expect($success->getData(true))->toMatchArray([
        'status'                 => 'succeeded',
        'payment_status'         => 'succeeded',
        'gateway_transaction_id' => 'instant-payment',
        'message'                => 'Payment processed successfully.',
    ]);
});

test('Stripe checkout maps configuration failures provider failures and pending sessions', function () {
    $gateway = invoiceControllerGateway('stripe');
    $invoice = invoiceControllerInvoice([
        'status'       => 'sent',
        'number'       => 'INV-STRIPE',
        'total_amount' => 1200,
        'balance'      => 1200,
    ]);
    $driver     = new PublicInvoiceStripeDriver();
    $controller = publicInvoiceController($driver);
    $request    = invoiceControllerRequest(['gateway_id' => $gateway->uuid]);

    $driver->result = new RuntimeException('Stripe client missing');
    expect($controller->pay($request, $invoice->uuid)->getStatusCode())->toBe(422);

    $driver->result = GatewayResponse::failure(message: null);
    expect($controller->pay($request, $invoice->uuid)->getData(true)['error'])->toContain('Failed to create');

    $driver->result = GatewayResponse::pending(
        'checkout-session',
        message: 'Redirect to Stripe',
        data: [
            'checkout_url'        => 'https://checkout.stripe.test/session',
            'checkout_session_id' => 'cs_test_one',
        ],
    );
    $response = $controller->pay($request, $invoice->uuid);
    expect($response->getData(true))->toMatchArray([
        'checkout_url'        => 'https://checkout.stripe.test/session',
        'checkout_session_id' => 'cs_test_one',
        'payment_url'         => 'https://checkout.stripe.test/session',
    ])->and($driver->calls[5][2])->toContain('payment=success')
        ->and($driver->calls[5][3])->toContain('payment=cancelled');
});

test('public refund handoff resolves invoice company wallet state and QR payload', function () {
    $gateway = invoiceControllerGateway('taler');
    $invoice = invoiceControllerInvoice([
        'status'       => 'refund_pending',
        'number'       => 'INV-REFUND',
        'currency'     => 'KUDOS',
        'total_amount' => 900,
        'amount_paid'  => 900,
    ]);
    Illuminate\Database\Capsule\Manager::table('companies')->insert([
        'uuid'       => $invoice->company_uuid,
        'public_id'  => 'company_public',
        'name'       => 'Refund Merchant',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $refund = invoiceControllerGatewayTransaction($gateway, [
        'type'               => 'refund',
        'amount'             => 450,
        'currency'           => null,
        'status'             => 'succeeded',
        'refund_status'      => null,
        'refund_accepted_at' => now(),
        'refund_expires_at'  => now()->addDay(),
        'processed_at'       => now(),
        'raw_response'       => [
            'invoice_uuid' => $invoice->public_id,
            'data'         => [
                'refund_status'    => 'pending_wallet_acceptance',
                'wallet_status'    => 'pending',
                'taler_refund_uri' => 'taler://refund/public',
            ],
        ],
    ]);
    Container::getInstance()->instance('DNS2D', new PublicInvoiceQrGenerator());
    Illuminate\Support\Facades\Facade::clearResolvedInstance('DNS2D');

    $data = publicInvoiceController(new PublicInvoiceAsyncDriver())
        ->refund($refund->public_id)
        ->getData(true)['refund'];

    expect($data)->toMatchArray([
        'id'                     => $refund->public_id,
        'amount'                 => 450,
        'currency'               => 'KUDOS',
        'status'                 => 'succeeded',
        'refund_status'          => 'pending_wallet_acceptance',
        'wallet_status'          => 'pending',
        'taler_refund_uri'       => 'taler://refund/public',
        'qr_text'                => 'taler://refund/public',
        'gateway_transaction_id' => $refund->gateway_reference_id,
        'invoice'                => [
            'id'     => $invoice->public_id,
            'number' => 'INV-REFUND',
            'status' => 'refund_pending',
        ],
        'company' => ['name' => 'Refund Merchant'],
    ])->and($data['qr_image'])->not->toBeNull()
        ->and($data['created_at'])->not->toBeNull()
        ->and($data['processed_at'])->not->toBeNull()
        ->and($data['refund_accepted_at'])->not->toBeNull()
        ->and($data['refund_expires_at'])->not->toBeNull();
});

test('public refunds reject missing URIs and tolerate QR rendering outages', function () {
    $gateway = invoiceControllerGateway('taler');
    $missing = invoiceControllerGatewayTransaction($gateway, [
        'type'         => 'refund',
        'raw_response' => [],
    ]);
    $controller = publicInvoiceController(new PublicInvoiceAsyncDriver());

    expect($controller->refund($missing->uuid)->getStatusCode())->toBe(404);

    $refund = invoiceControllerGatewayTransaction($gateway, [
        'type'         => 'refund',
        'raw_response' => ['refund_url' => 'taler://refund/fallback'],
    ]);
    Container::getInstance()->instance('DNS2D', new PublicInvoiceQrGenerator(true));
    Illuminate\Support\Facades\Facade::clearResolvedInstance('DNS2D');

    $data = $controller->refund($refund->gateway_reference_id)->getData(true)['refund'];
    expect($data['qr_image'])->toBeNull()
        ->and($data['invoice'])->toBeNull()
        ->and($data['company']['name'])->toBeNull();
});

test('public refund URI and invoice resolution support every provider response shape', function () {
    $gateway = invoiceControllerGateway('taler');
    $invoice = invoiceControllerInvoice(['status' => 'partial_refund_pending']);
    Container::getInstance()->instance('DNS2D', new PublicInvoiceQrGenerator());
    Illuminate\Support\Facades\Facade::clearResolvedInstance('DNS2D');
    $controller = publicInvoiceController(new PublicInvoiceAsyncDriver());
    $shapes     = [
        ['data' => ['refund_url' => 'taler://refund/data-url', 'invoice_uuid' => $invoice->uuid]],
        ['taler_refund_uri' => 'taler://refund/top-taler', 'metadata' => ['invoice_uuid' => $invoice->uuid]],
        ['refund_url'       => 'taler://refund/top-url', 'invoice_uuid' => $invoice->uuid],
    ];

    foreach ($shapes as $index => $rawResponse) {
        $refund = invoiceControllerGatewayTransaction($gateway, [
            'uuid'                 => 'refund-shape-' . $index,
            'public_id'            => 'refund_shape_' . $index,
            'gateway_reference_id' => 'refund-provider-shape-' . $index,
            'type'                 => 'refund',
            'raw_response'         => $rawResponse,
        ]);
        $payload = $controller->refund($refund->uuid)->getData(true)['refund'];
        expect($payload['taler_refund_uri'])->toStartWith('taler://refund/')
            ->and($payload['invoice']['id'])->toBe($invoice->public_id);
    }
});
