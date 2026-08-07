<?php

use Fleetbase\Ledger\DTO\GatewayResponse;
use Fleetbase\Ledger\DTO\PurchaseRequest;
use Fleetbase\Ledger\DTO\RefundRequest;
use Fleetbase\Ledger\Http\Controllers\Internal\v1\GatewayController;
use Fleetbase\Ledger\Models\Gateway;
use Fleetbase\Ledger\Models\GatewayTransaction;
use Fleetbase\Ledger\PaymentGatewayManager;
use Fleetbase\Ledger\Services\PaymentService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

class GatewayControllerRequest extends Request
{
    public function validate(array $rules, ...$params)
    {
        return $this->all();
    }
}

class GatewayControllerPaymentService extends PaymentService
{
    public array $calls    = [];
    public array $manifest = [];
    public GatewayResponse $chargeResponse;
    public GatewayResponse $refundResponse;
    public GatewayResponse $setupResponse;

    public function __construct()
    {
    }

    public function getDriverManifest(): array
    {
        return $this->manifest;
    }

    public function charge(string $gatewayIdentifier, PurchaseRequest $request): GatewayResponse
    {
        $this->calls[] = ['charge', $gatewayIdentifier, $request];

        return $this->chargeResponse;
    }

    public function refund(string $gatewayIdentifier, RefundRequest $request): GatewayResponse
    {
        $this->calls[] = ['refund', $gatewayIdentifier, $request];

        return $this->refundResponse;
    }

    public function createPaymentMethod(string $gatewayIdentifier, array $data): GatewayResponse
    {
        $this->calls[] = ['setup', $gatewayIdentifier, $data];

        return $this->setupResponse;
    }
}

class GatewayControllerDriver
{
    public array $calls                        = [];
    public array $credentialResult             = [];
    public array $webhookResult                = [];
    public ?GatewayResponse $testOrderResponse = null;

    public function initialize(array $config = [], bool $sandbox = false): static
    {
        $this->calls[] = ['initialize', $config, $sandbox];

        return $this;
    }

    public function testCredentials(): array
    {
        $this->calls[] = ['testCredentials'];

        return $this->credentialResult;
    }

    public function registerWebhook(array $options): array
    {
        $this->calls[] = ['registerWebhook', $options];

        return $this->webhookResult;
    }

    public function createTestOrder(array $options): GatewayResponse
    {
        $this->calls[] = ['createTestOrder', $options];

        return $this->testOrderResponse;
    }
}

class GatewayControllerUnsupportedDriver
{
    public function initialize(array $config = [], bool $sandbox = false): static
    {
        return $this;
    }
}

class GatewayControllerEncrypter
{
    public function encrypt(mixed $value, bool $serialize = true): string
    {
        return base64_encode(serialize($value));
    }

    public function decrypt(string $payload, bool $unserialize = true): mixed
    {
        return unserialize(base64_decode($payload));
    }
}

class GatewayControllerManager extends PaymentGatewayManager
{
    public function __construct(public mixed $testDriver, public bool $throw = false)
    {
    }

    public function driver($driver = null)
    {
        if ($this->throw) {
            throw new RuntimeException('driver unavailable');
        }

        return $this->testDriver;
    }

    public function getDefaultDriver(): string
    {
        return 'taler';
    }
}

function gatewayControllerRequest(array $input = []): GatewayControllerRequest
{
    return GatewayControllerRequest::create('/ledger/gateways', 'POST', $input);
}

function bootGatewayControllerDatabase(): void
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
    Gateway::encryptUsing(new GatewayControllerEncrypter());
    Facade::clearResolvedInstance('db');
    session(['company' => 'company-gateway-controller']);

    $schema = $capsule->getConnection('testing')->getSchemaBuilder();
    $schema->create('ledger_gateways', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('_key')->nullable();
        $table->string('company_uuid');
        $table->string('name')->nullable();
        $table->string('driver');
        $table->text('description')->nullable();
        $table->text('config')->nullable();
        $table->text('capabilities')->nullable();
        $table->text('meta')->nullable();
        $table->boolean('is_sandbox')->default(false);
        $table->string('environment')->nullable();
        $table->string('status');
        $table->string('return_url')->nullable();
        $table->string('webhook_url')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('ledger_gateway_transactions', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('_key')->nullable();
        $table->string('company_uuid');
        $table->string('gateway_uuid');
        $table->string('transaction_uuid')->nullable();
        $table->string('gateway_reference_id')->nullable();
        $table->string('type');
        $table->string('event_type')->nullable();
        $table->bigInteger('amount')->nullable();
        $table->string('currency')->nullable();
        $table->string('status');
        $table->text('message')->nullable();
        $table->text('raw_response')->nullable();
        $table->timestamp('processed_at')->nullable();
        $table->string('reconciliation_status')->nullable();
        $table->timestamp('reconciliation_checked_at')->nullable();
        $table->text('reconciliation_data')->nullable();
        $table->string('refund_status')->nullable();
        $table->timestamp('refund_accepted_at')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
}

function gatewayControllerGateway(array $attributes = []): Gateway
{
    static $sequence = 0;
    $sequence++;

    return Gateway::withoutEvents(function () use ($attributes, $sequence) {
        $gateway = new Gateway();
        $gateway->forceFill(array_merge([
            'uuid'         => 'gateway-controller-' . $sequence,
            'public_id'    => 'gateway_public_' . $sequence,
            'company_uuid' => 'company-gateway-controller',
            'name'         => 'Gateway ' . $sequence,
            'driver'       => 'taler',
            'capabilities' => ['purchase', 'refund'],
            'meta'         => [],
            'is_sandbox'   => true,
            'environment'  => 'sandbox',
            'status'       => 'active',
        ], $attributes));
        $gateway->save();

        return $gateway;
    });
}

function gatewayControllerTransaction(Gateway $gateway, array $attributes = []): GatewayTransaction
{
    static $sequence = 0;
    $sequence++;

    return GatewayTransaction::withoutEvents(function () use ($gateway, $attributes, $sequence) {
        $transaction = new GatewayTransaction();
        $transaction->forceFill(array_merge([
            'uuid'                 => 'gateway-controller-transaction-' . $sequence,
            'public_id'            => 'gtxn_controller_' . $sequence,
            'company_uuid'         => $gateway->company_uuid,
            'gateway_uuid'         => $gateway->uuid,
            'gateway_reference_id' => 'provider-' . $sequence,
            'type'                 => 'purchase',
            'event_type'           => GatewayResponse::EVENT_PAYMENT_SUCCEEDED,
            'amount'               => 100,
            'currency'             => 'USD',
            'status'               => 'succeeded',
            'raw_response'         => [],
        ], $attributes));
        $transaction->save();

        return $transaction;
    });
}

test('gateway driver catalog returns normalized manifests', function () {
    $paymentService           = new GatewayControllerPaymentService();
    $paymentService->manifest = [[
        'code'         => 'taler',
        'name'         => 'GNU Taler',
        'capabilities' => ['purchase', 'refund'],
    ]];
    $controller = new GatewayController($paymentService);

    $response = $controller->drivers();

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'status'  => 'ok',
            'drivers' => $paymentService->manifest,
        ]);
});

test('gateway charge constructs the complete purchase contract and maps successful responses', function () {
    $paymentService                 = new GatewayControllerPaymentService();
    $paymentService->chargeResponse = GatewayResponse::success(
        'payment-one',
        message: 'Captured',
        amount: 1250,
        currency: 'USD',
        data: ['receipt_url' => 'https://merchant.test/receipt'],
    );
    $controller = new GatewayController($paymentService);
    $request    = gatewayControllerRequest([
        'amount'               => 1250,
        'currency'             => 'usd',
        'description'          => 'Invoice payment',
        'payment_method_token' => 'token-one',
        'customer_id'          => 'customer-one',
        'customer_email'       => 'billing@example.test',
        'invoice_uuid'         => 'invoice-one',
        'order_uuid'           => 'order-one',
        'return_url'           => 'https://app.test/return',
        'cancel_url'           => 'https://app.test/cancel',
        'metadata'             => ['source' => 'invoice'],
    ]);

    $response                 = $controller->charge($request, 'gateway-one');
    [, $gatewayId, $purchase] = $paymentService->calls[0];

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toMatchArray([
            'status'                 => 'succeeded',
            'successful'             => true,
            'gateway_transaction_id' => 'payment-one',
            'message'                => 'Captured',
        ])
        ->and($gatewayId)->toBe('gateway-one')
        ->and($purchase->amount)->toBe(1250)
        ->and($purchase->currency)->toBe('USD')
        ->and($purchase->description)->toBe('Invoice payment')
        ->and($purchase->paymentMethodToken)->toBe('token-one')
        ->and($purchase->customerId)->toBe('customer-one')
        ->and($purchase->customerEmail)->toBe('billing@example.test')
        ->and($purchase->invoiceUuid)->toBe('invoice-one')
        ->and($purchase->orderUuid)->toBe('order-one')
        ->and($purchase->returnUrl)->toBe('https://app.test/return')
        ->and($purchase->cancelUrl)->toBe('https://app.test/cancel')
        ->and($purchase->metadata)->toBe(['source' => 'invoice']);
});

test('gateway charge returns provider failures with an unprocessable status', function () {
    $paymentService                 = new GatewayControllerPaymentService();
    $paymentService->chargeResponse = GatewayResponse::failure(
        'payment-failed',
        message: 'Card declined',
        errorCode: 'declined',
    );
    $controller = new GatewayController($paymentService);

    $response = $controller->charge(gatewayControllerRequest([
        'amount'      => 500,
        'currency'    => 'EUR',
        'description' => 'Failed payment',
    ]), 'gateway-card');

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true))->toMatchArray([
            'status'                 => 'failed',
            'successful'             => false,
            'gateway_transaction_id' => 'payment-failed',
            'message'                => 'Card declined',
            'data'                   => [],
        ]);
});

test('gateway refund forwards invoice metadata and maps both response states', function () {
    $paymentService                 = new GatewayControllerPaymentService();
    $paymentService->refundResponse = GatewayResponse::success(
        'refund-one',
        GatewayResponse::EVENT_REFUND_PROCESSED,
        'Refund approved',
        300,
        'USD',
        data: ['refund_uri' => 'taler://refund'],
    );
    $controller = new GatewayController($paymentService);
    $request    = gatewayControllerRequest([
        'gateway_transaction_id' => 'payment-one',
        'amount'                 => 300,
        'currency'               => 'usd',
        'reason'                 => 'Damaged parcel',
        'invoice_uuid'           => 'invoice-one',
        'metadata'               => ['requested_by' => 'customer'],
    ]);

    $response               = $controller->refund($request, 'gateway-one');
    [, $gatewayId, $refund] = $paymentService->calls[0];

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['gateway_transaction_id'])->toBe('refund-one')
        ->and($gatewayId)->toBe('gateway-one')
        ->and($refund->gatewayTransactionId)->toBe('payment-one')
        ->and($refund->amount)->toBe(300)
        ->and($refund->currency)->toBe('USD')
        ->and($refund->reason)->toBe('Damaged parcel')
        ->and($refund->invoiceUuid)->toBe('invoice-one')
        ->and($refund->metadata)->toBe(['requested_by' => 'customer']);

    $paymentService->refundResponse = GatewayResponse::failure(
        'refund-failed',
        GatewayResponse::EVENT_REFUND_FAILED,
        'Refund rejected',
    );
    expect($controller->refund($request, 'gateway-one')->getStatusCode())->toBe(422);
});

test('setup intent forwards arbitrary provider data and preserves response status', function () {
    $paymentService                = new GatewayControllerPaymentService();
    $paymentService->setupResponse = GatewayResponse::pending(
        'setup-one',
        GatewayResponse::EVENT_SETUP_SUCCEEDED,
        'Confirmation required',
        data: ['client_secret' => 'safe-secret'],
    );
    $controller = new GatewayController($paymentService);
    $request    = gatewayControllerRequest([
        'customer_id'     => 'customer-one',
        'billing_details' => ['country' => 'MN'],
    ]);

    $response = $controller->setupIntent($request, 'gateway-one');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'status'     => 'pending',
            'successful' => true,
            'message'    => 'Confirmation required',
            'data'       => ['client_secret' => 'safe-secret'],
        ])
        ->and($paymentService->calls[0])->toBe([
            'setup',
            'gateway-one',
            ['customer_id' => 'customer-one', 'billing_details' => ['country' => 'MN']],
        ]);

    $paymentService->setupResponse = GatewayResponse::failure(message: 'Unsupported');
    expect($controller->setupIntent($request, 'gateway-one')->getStatusCode())->toBe(422);
});

test('gateway summary reports configuration counts warnings and latest activity', function () {
    bootGatewayControllerDatabase();
    $active = gatewayControllerGateway(['driver' => 'taler', 'webhook_url' => null]);
    gatewayControllerGateway([
        'driver'      => 'cash',
        'status'      => 'inactive',
        'environment' => 'live',
        'is_sandbox'  => false,
    ]);
    gatewayControllerTransaction($active, ['created_at' => now()->subMinutes(3)]);
    gatewayControllerTransaction($active, [
        'type'       => 'refund',
        'event_type' => GatewayResponse::EVENT_REFUND_PROCESSED,
        'created_at' => now()->subMinutes(2),
    ]);
    gatewayControllerTransaction($active, [
        'type'                      => 'settlement',
        'event_type'                => null,
        'reconciliation_status'     => 'matched',
        'reconciliation_checked_at' => now()->subMinute(),
    ]);
    $paymentService           = new GatewayControllerPaymentService();
    $paymentService->manifest = [
        ['code' => 'taler', 'name' => 'GNU Taler', 'capabilities' => ['purchase', 'refund']],
        ['code' => 'cash', 'name' => 'Cash'],
        ['code' => 'stripe', 'name' => 'Stripe'],
    ];

    $data = (new GatewayController($paymentService))->summary()->getData(true);

    expect($data['summary'])->toMatchArray([
        'total_gateways'   => 2,
        'active_gateways'  => 1,
        'live_gateways'    => 1,
        'sandbox_gateways' => 1,
        'webhook_warnings' => 1,
    ])->and($data['summary']['last_payment_at'])->not->toBeNull()
        ->and($data['summary']['last_refund_at'])->not->toBeNull()
        ->and($data['summary']['last_settlement_at'])->not->toBeNull()
        ->and($data['drivers'][0])->toMatchArray(['code' => 'taler', 'configured' => 1, 'active' => 1, 'sandbox' => 1])
        ->and($data['drivers'][2])->toMatchArray(['code' => 'stripe', 'configured' => 0]);
});

test('gateway summary handles companies without configured gateways', function () {
    bootGatewayControllerDatabase();
    $paymentService           = new GatewayControllerPaymentService();
    $paymentService->manifest = [['code' => 'cash', 'name' => 'Cash']];

    $data = (new GatewayController($paymentService))->summary()->getData(true);

    expect($data['summary'])->toMatchArray([
        'total_gateways'     => 0,
        'active_gateways'    => 0,
        'last_payment_at'    => null,
        'last_refund_at'     => null,
        'last_settlement_at' => null,
    ]);
});

test('gateway transaction listing applies type status pagination and tenant resolution', function () {
    bootGatewayControllerDatabase();
    $gateway = gatewayControllerGateway();
    gatewayControllerTransaction($gateway, ['type' => 'purchase', 'status' => 'succeeded']);
    gatewayControllerTransaction($gateway, ['type' => 'refund', 'status' => 'failed']);
    gatewayControllerTransaction($gateway, ['type' => 'refund', 'status' => 'succeeded']);
    $request = gatewayControllerRequest(['type' => 'refund', 'status' => 'succeeded', 'per_page' => 1]);
    Container::getInstance()->instance('request', $request);

    $data = (new GatewayController(new GatewayControllerPaymentService()))
        ->transactions($request, $gateway->public_id)
        ->getData(true);
    $records = $data['data'] ?? $data['gateway_transactions'] ?? $data;

    expect($records)->toHaveCount(1)
        ->and($records[0]['type'])->toBe('refund')
        ->and($records[0]['status'])->toBe('succeeded');
});

test('credential diagnostics sanitize provider payloads and persist safe summaries', function () {
    bootGatewayControllerDatabase();
    $gateway                  = gatewayControllerGateway();
    $driver                   = new GatewayControllerDriver();
    $driver->credentialResult = [
        'ok'           => true,
        'status'       => 'success',
        'message'      => 'Authenticated',
        'http_status'  => 200,
        'metadata'     => ['merchant' => 'demo'],
        'raw_response' => ['secret' => 'must-not-leak'],
        'raw'          => 'must-not-leak',
    ];
    Container::getInstance()->instance(PaymentGatewayManager::class, new GatewayControllerManager($driver));
    $controller = new GatewayController(new GatewayControllerPaymentService());

    $response = $controller->testCredentials(gatewayControllerRequest(), $gateway->uuid);
    $gateway->refresh();

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->not->toHaveKeys(['raw_response', 'raw'])
        ->and(data_get($gateway->meta, 'diagnostics.last_credential_test'))->toMatchArray([
            'status'      => 'success',
            'successful'  => true,
            'message'     => 'Authenticated',
            'http_status' => 200,
        ]);

    $driver->credentialResult = ['ok' => false, 'message' => 'Denied'];
    expect($controller->testCredentials(gatewayControllerRequest(), $gateway->public_id)->getStatusCode())->toBe(422);
});

test('unsupported credential diagnostics and unavailable draft drivers return safe errors', function () {
    bootGatewayControllerDatabase();
    $gateway = gatewayControllerGateway(['driver' => 'cash']);
    Container::getInstance()->instance(
        PaymentGatewayManager::class,
        new GatewayControllerManager(new GatewayControllerUnsupportedDriver()),
    );
    $controller = new GatewayController(new GatewayControllerPaymentService());

    expect($controller->testCredentials(gatewayControllerRequest(), $gateway->uuid)->getStatusCode())->toBe(422);

    $draft = gatewayControllerRequest([
        'driver'      => 'cash',
        'environment' => 'live',
        'config'      => ['token' => 'secret'],
    ]);
    expect($controller->testDraftCredentials($draft)->getStatusCode())->toBe(422);

    Container::getInstance()->instance(
        PaymentGatewayManager::class,
        new GatewayControllerManager(new GatewayControllerUnsupportedDriver(), true),
    );
    expect($controller->testDraftCredentials($draft)->getData(true)['message'])->toContain('not available');
});

test('draft credential diagnostics initialize sandbox state and sanitize responses', function () {
    bootGatewayControllerDatabase();
    $driver                   = new GatewayControllerDriver();
    $driver->credentialResult = ['ok' => true, 'raw' => ['token' => 'secret']];
    Container::getInstance()->instance(PaymentGatewayManager::class, new GatewayControllerManager($driver));
    $controller = new GatewayController(new GatewayControllerPaymentService());

    $response = $controller->testDraftCredentials(gatewayControllerRequest([
        'driver' => 'taler',
        'config' => ['backend_url' => 'https://merchant.test'],
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['ok' => true])
        ->and($driver->calls[0])->toBe([
            'initialize',
            ['backend_url' => 'https://merchant.test'],
            true,
        ]);
});

test('test orders persist provider audit records and diagnostic summaries', function () {
    bootGatewayControllerDatabase();
    $gateway                   = gatewayControllerGateway();
    $driver                    = new GatewayControllerDriver();
    $driver->testOrderResponse = GatewayResponse::pending(
        'test-order-one',
        message: 'Awaiting payment',
        rawResponse: ['order_status' => 'unpaid'],
        data: ['payment_uri' => 'taler://pay'],
    );
    Container::getInstance()->instance(PaymentGatewayManager::class, new GatewayControllerManager($driver));
    $controller = new GatewayController(new GatewayControllerPaymentService());

    $response = $controller->createTestOrder(gatewayControllerRequest([
        'amount'      => 5,
        'currency'    => 'kudos',
        'description' => 'Connectivity check',
    ]), $gateway->uuid);
    $audit = GatewayTransaction::query()->firstOrFail();
    $gateway->refresh();

    expect($response->getStatusCode())->toBe(200)
        ->and($audit->type)->toBe('test_order')
        ->and($audit->gateway_reference_id)->toBe('test-order-one')
        ->and(data_get($audit->raw_response, 'data.payment_uri'))->toBe('taler://pay')
        ->and(data_get($gateway->meta, 'diagnostics.last_test_order.gateway_transaction_id'))->toBe('test-order-one')
        ->and($driver->calls[1][1]['metadata'])->toMatchArray([
            'company_uuid' => $gateway->company_uuid,
            'gateway_uuid' => $gateway->uuid,
        ]);

    $driver->testOrderResponse = GatewayResponse::failure(message: 'Backend rejected test');
    expect($controller->createTestOrder(gatewayControllerRequest(), $gateway->uuid)->getStatusCode())->toBe(422);
});

test('test order and webhook endpoints report unsupported drivers', function () {
    bootGatewayControllerDatabase();
    $gateway = gatewayControllerGateway(['driver' => 'cash']);
    Container::getInstance()->instance(
        PaymentGatewayManager::class,
        new GatewayControllerManager(new GatewayControllerUnsupportedDriver()),
    );
    $controller = new GatewayController(new GatewayControllerPaymentService());

    expect($controller->createTestOrder(gatewayControllerRequest(), $gateway->uuid)->getStatusCode())->toBe(422)
        ->and($controller->registerWebhook(gatewayControllerRequest(), $gateway->uuid)->getStatusCode())->toBe(422);
});

test('webhook registration uses system defaults persists returned urls and removes raw data', function () {
    bootGatewayControllerDatabase();
    $gateway               = gatewayControllerGateway(['webhook_url' => null]);
    $driver                = new GatewayControllerDriver();
    $driver->webhookResult = [
        'ok'           => true,
        'status'       => 'success',
        'payload'      => ['url' => 'https://hooks.example.test/taler'],
        'raw_response' => ['token' => 'secret'],
    ];
    Container::getInstance()->instance(PaymentGatewayManager::class, new GatewayControllerManager($driver));
    $controller = new GatewayController(new GatewayControllerPaymentService());

    $response = $controller->registerWebhook(gatewayControllerRequest(), $gateway->public_id);
    $gateway->refresh();

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->not->toHaveKey('raw_response')
        ->and($gateway->webhook_url)->toBe('https://hooks.example.test/taler')
        ->and($driver->calls[1][1]['company_uuid'])->toBe($gateway->company_uuid)
        ->and($driver->calls[1][1]['webhook_url'])->toContain('/ledger/webhooks/taler')
        ->and(data_get($gateway->meta, 'diagnostics.last_webhook_registration.webhook_url'))
        ->toBe('https://hooks.example.test/taler');

    $driver->webhookResult = ['ok' => false, 'message' => 'Registration denied'];
    expect($controller->registerWebhook(
        gatewayControllerRequest(['webhook_url' => 'https://custom.test/hook']),
        $gateway->uuid,
    )->getStatusCode())->toBe(422);
});

test('gateway diagnostics expose safe configuration summaries and latest provider activity', function () {
    bootGatewayControllerDatabase();
    $gateway = gatewayControllerGateway([
        'config' => [
            'backend_url'   => 'https://merchant.test',
            'instance_name' => 'merchant-demo',
            'secret_token'  => 'abcdefghijkl',
            'api_key'       => 'short',
            'empty_secret'  => '',
            'nested'        => ['ignored' => true],
        ],
        'webhook_url' => 'https://hooks.test/taler',
        'meta'        => [
            'diagnostics' => [
                'last_credential_test' => [
                    'status'     => 'success',
                    'message'    => 'Authenticated',
                    'checked_at' => '2026-01-01T00:00:00+00:00',
                ],
                'last_webhook_registration' => ['checked_at' => '2026-01-02T00:00:00+00:00'],
                'last_test_order'           => [
                    'checked_at'             => '2026-01-03T00:00:00+00:00',
                    'gateway_transaction_id' => 'test-order-one',
                ],
            ],
        ],
    ]);
    gatewayControllerTransaction($gateway, ['type' => 'webhook_event']);
    gatewayControllerTransaction($gateway, [
        'type'       => 'purchase',
        'event_type' => GatewayResponse::EVENT_PAYMENT_PENDING,
    ]);
    gatewayControllerTransaction($gateway, ['type' => 'refund']);
    gatewayControllerTransaction($gateway, [
        'type'                      => 'settlement',
        'event_type'                => null,
        'reconciliation_status'     => 'matched',
        'reconciliation_checked_at' => now(),
    ]);
    $controller = new GatewayController(new GatewayControllerPaymentService());

    $data   = $controller->diagnostics(gatewayControllerRequest(), $gateway->uuid)->getData(true);
    $config = collect($data['gateway']['config_summary'])->keyBy('label');

    expect($data['diagnostics'])->toMatchArray([
        'credential_status'            => 'success',
        'last_credential_test_message' => 'Authenticated',
        'last_test_order_id'           => 'test-order-one',
        'webhook_registration'         => 'configured',
        'last_reconciliation_status'   => 'matched',
    ])->and($data['last_webhook'])->not->toBeNull()
        ->and($data['last_payment'])->not->toBeNull()
        ->and($data['last_refund'])->not->toBeNull()
        ->and($data['last_settlement'])->not->toBeNull()
        ->and($config['Backend Url']['value'])->toBe('https://merchant.test')
        ->and($config['Instance Name']['value'])->toBe('merchant-demo')
        ->and($config['Secret Token']['value'])->toBe('abc****jkl')
        ->and($config['Api Key']['value'])->toBe('****')
        ->and($config['Empty Secret']['value'])->toBe('')
        ->and($config)->not->toHaveKey('Nested');
});

test('gateway diagnostics return explicit empty state before provider activity', function () {
    bootGatewayControllerDatabase();
    $gateway = gatewayControllerGateway(['meta' => [], 'webhook_url' => null]);

    $data = (new GatewayController(new GatewayControllerPaymentService()))
        ->diagnostics(gatewayControllerRequest(), $gateway->public_id)
        ->getData(true);

    expect($data['diagnostics'])->toMatchArray([
        'credential_status'         => 'not_checked',
        'webhook_registration'      => 'not_configured',
        'last_webhook_received_at'  => null,
        'last_payment_event_at'     => null,
        'last_refund_event_at'      => null,
        'last_settlement_seen_at'   => null,
        'last_credential_test'      => null,
        'last_webhook_registration' => null,
        'last_test_order'           => null,
    ])->and($data['last_webhook'])->toBeNull()
        ->and($data['last_payment'])->toBeNull()
        ->and($data['last_refund'])->toBeNull()
        ->and($data['last_settlement'])->toBeNull();
});
