<?php

use Fleetbase\Ledger\Models\Gateway;
use Fleetbase\Ledger\Models\GatewayTransaction;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\PaymentGatewayManager;
use Fleetbase\Ledger\Services\TalerRefundVerificationService;
use Fleetbase\TestSupport\LoggerManager;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

class TalerRefundStatusDriver
{
    public array $calls          = [];
    public array $results        = [];
    public ?Throwable $exception = null;

    public function initialize(array $config = [], bool $sandbox = false): static
    {
        $this->calls[] = ['initialize', $config, $sandbox];

        return $this;
    }

    public function fetchRefundStatus(string $orderId, int $amount, ?string $currency): array
    {
        $this->calls[] = ['fetchRefundStatus', $orderId, $amount, $currency];

        if ($this->exception) {
            throw $this->exception;
        }

        return array_shift($this->results) ?? [];
    }
}

class TalerRefundUnsupportedDriver
{
    public function initialize(array $config = [], bool $sandbox = false): static
    {
        return $this;
    }
}

class TalerRefundGatewayManager extends PaymentGatewayManager
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
        return 'taler';
    }
}

function bootTalerRefundVerificationDatabase(): void
{
    $capsule    = new Capsule(Container::getInstance());
    $dispatcher = new Dispatcher(Container::getInstance());
    $capsule->setEventDispatcher($dispatcher);
    Model::setEventDispatcher($dispatcher);
    Model::clearBootedModels();
    $capsule->addConnection([
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ], 'testing');
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('testing');
    Container::getInstance()->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstance('db');

    $schema = $capsule->getConnection('testing')->getSchemaBuilder();
    $schema->create('ledger_gateways', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('_key')->nullable();
        $table->string('company_uuid');
        $table->string('name')->nullable();
        $table->string('driver');
        $table->text('config')->nullable();
        $table->boolean('is_sandbox')->default(false);
        $table->string('environment')->nullable();
        $table->string('status');
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('ledger_gateway_transactions', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('_key')->nullable();
        $table->string('company_uuid');
        $table->string('gateway_uuid')->nullable();
        $table->string('gateway_reference_id')->nullable();
        $table->string('type');
        $table->bigInteger('amount')->default(0);
        $table->string('currency')->nullable();
        $table->string('status');
        $table->text('raw_response')->nullable();
        $table->string('refund_status')->nullable();
        $table->timestamp('refund_accepted_at')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('ledger_invoices', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('_key')->nullable();
        $table->string('company_uuid');
        $table->string('customer_uuid')->nullable();
        $table->string('customer_type')->nullable();
        $table->string('number')->nullable();
        $table->bigInteger('total_amount')->default(0);
        $table->bigInteger('amount_paid')->default(0);
        $table->bigInteger('balance')->default(0);
        $table->string('currency')->nullable();
        $table->string('status');
        $table->text('meta')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    foreach (['ledger_invoice_items', 'orders', 'templates', 'customers'] as $tableName) {
        $schema->create($tableName, function (Blueprint $table) {
            $table->string('uuid')->primary();
            $table->string('invoice_uuid')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }
}

function talerVerificationGateway(
    string $uuid = 'gateway-taler',
    string $company = 'company-taler',
    string $driver = 'taler',
    string $status = 'active',
): Gateway {
    return Gateway::withoutEvents(function () use ($uuid, $company, $driver, $status) {
        $gateway = new Gateway();
        $gateway->forceFill([
            'uuid'         => $uuid,
            'public_id'    => 'gateway_' . $uuid,
            'company_uuid' => $company,
            'name'         => 'Taler',
            'driver'       => $driver,
            'is_sandbox'   => true,
            'environment'  => 'sandbox',
            'status'       => $status,
        ]);
        $gateway->save();

        return $gateway;
    });
}

function talerVerificationRefund(array $attributes = []): GatewayTransaction
{
    static $sequence = 0;
    $sequence++;
    $uuid = $attributes['uuid'] ?? sprintf('refund-%03d', $sequence);

    return GatewayTransaction::withoutEvents(function () use ($attributes, $uuid) {
        $refund = new GatewayTransaction();
        $refund->forceFill(array_merge([
            'uuid'                 => $uuid,
            'public_id'            => 'gtxn_' . $uuid,
            'company_uuid'         => 'company-taler',
            'gateway_uuid'         => 'gateway-taler',
            'gateway_reference_id' => 'order-one',
            'type'                 => 'refund',
            'amount'               => 300,
            'currency'             => 'USD',
            'status'               => 'succeeded',
            'refund_status'        => 'pending_wallet_acceptance',
            'raw_response'         => ['invoice_uuid' => 'invoice-taler'],
            'created_at'           => now(),
            'updated_at'           => now(),
        ], $attributes));
        $refund->save();

        return $refund;
    });
}

function talerVerificationInvoice(array $attributes = []): Invoice
{
    return Invoice::withoutEvents(function () use ($attributes) {
        $invoice = new Invoice();
        $invoice->forceFill(array_merge([
            'uuid'         => 'invoice-taler',
            'public_id'    => 'invoice_taler',
            'company_uuid' => 'company-taler',
            'number'       => 'INV-TALER',
            'total_amount' => 1000,
            'amount_paid'  => 1000,
            'balance'      => 0,
            'currency'     => 'USD',
            'status'       => 'refund_pending',
            'meta'         => ['refunded_amount' => 1000],
        ], $attributes));
        $invoice->save();

        return $invoice;
    });
}

beforeEach(function () {
    bootTalerRefundVerificationDatabase();
    LoggerManager::$records = [];
});

test('pending verification applies gateway and refund filters and summarizes outcomes', function () {
    talerVerificationGateway();
    talerVerificationGateway('gateway-other-company', 'company-other');
    talerVerificationGateway('gateway-inactive', 'company-taler', 'taler', 'inactive');
    talerVerificationInvoice();
    talerVerificationRefund(['uuid' => 'refund-accepted', 'amount' => 300]);
    talerVerificationRefund([
        'uuid'                 => 'refund-pending',
        'amount'               => 200,
        'gateway_reference_id' => 'order-two',
        'raw_response'         => ['invoice_uuid' => null, 'data' => ['order_id' => 'order-two']],
    ]);

    $driver          = new TalerRefundStatusDriver();
    $driver->results = [
        ['http_status' => 200, 'data' => ['refund_pending' => false, 'refund_taken' => 'USD:3']],
        ['http_status' => 200, 'data' => ['refund_pending' => true, 'refund_taken' => 'USD:0']],
    ];
    $service = new TalerRefundVerificationService(new TalerRefundGatewayManager($driver));

    $summary = $service->verifyPending([
        'company' => 'company-taler',
        'gateway' => 'gateway_gateway-taler',
        'limit'   => 10,
    ]);

    expect($summary)->toMatchArray(['checked' => 2, 'accepted' => 1, 'pending' => 1, 'errors' => 0])
        ->and($summary['results'][0]['target_amount'])->toBe(300)
        ->and($summary['results'][1]['target_amount'])->toBe(200)
        ->and(GatewayTransaction::query()->find('refund-accepted')->refund_status)->toBe('accepted')
        ->and(GatewayTransaction::query()->find('refund-pending')->refund_status)->toBe('pending_wallet_acceptance');

    $filtered = $service->verifyPending([
        'gateway' => 'gateway-taler',
        'refund'  => 'does-not-exist',
        'limit'   => 1,
    ]);
    expect($filtered['checked'])->toBe(0);

    talerVerificationRefund([
        'uuid'                 => 'refund-summary-error',
        'gateway_reference_id' => null,
        'raw_response'         => [],
    ]);
    $errored = $service->verifyPending([
        'gateway' => 'gateway-taler',
        'refund'  => 'refund-summary-error',
    ]);
    expect($errored)->toMatchArray(['checked' => 1, 'accepted' => 0, 'pending' => 0, 'errors' => 1]);
});

test('accepted refunds use cumulative order totals and finalize invoice state', function () {
    $gateway = talerVerificationGateway();
    $invoice = talerVerificationInvoice();
    talerVerificationRefund([
        'uuid'               => 'refund-first',
        'amount'             => 250,
        'refund_status'      => 'accepted',
        'refund_accepted_at' => now(),
        'created_at'         => now()->subMinute(),
    ]);
    $refund = talerVerificationRefund([
        'uuid'         => 'refund-second',
        'amount'       => 750,
        'raw_response' => ['metadata' => ['invoice_uuid' => 'invoice_taler'], 'original_gateway_reference_id' => 'order-one'],
    ]);
    $driver            = new TalerRefundStatusDriver();
    $driver->results[] = [
        'http_status' => 200,
        'data'        => [
            'refund_pending' => false,
            'refund_taken'   => 'USD:10.00',
            'refund_amount'  => 'USD:10',
            'order_status'   => 'paid',
        ],
    ];
    $service = new TalerRefundVerificationService(new TalerRefundGatewayManager($driver));

    $result = $service->verifyRefund($refund, $gateway);
    $refund->refresh();
    $invoice->refresh();

    expect($result['status'])->toBe('accepted')
        ->and($result['target_amount'])->toBe(1000)
        ->and($refund->refund_accepted_at)->not->toBeNull()
        ->and(data_get($refund->raw_response, 'data.wallet_status'))->toBe('accepted')
        ->and(data_get($refund->raw_response, 'refund_verification.order_status'))->toBe('paid')
        ->and($invoice->status)->toBe('refunded')
        ->and(data_get($invoice->meta, 'pending_wallet_refund_amount'))->toBe(0)
        ->and($driver->calls[1])->toBe(['fetchRefundStatus', 'order-one', 1000, 'USD']);
});

test('wallet acceptance rejects provider pending insufficient malformed and currency mismatches', function () {
    $gateway         = talerVerificationGateway();
    $driver          = new TalerRefundStatusDriver();
    $driver->results = [
        ['data' => ['refund_pending' => 'true', 'refund_taken' => 'USD:9']],
        ['data' => ['refund_pending' => false, 'refund_taken' => 'USD:0']],
        ['data' => ['refund_pending' => false, 'refund_taken' => 'not-an-amount']],
        ['data' => ['refund_pending' => false, 'refund_taken' => 'EUR:9']],
        ['data' => ['refund_pending' => false, 'refund_taken' => 'USD:2.9']],
    ];
    $service = new TalerRefundVerificationService(new TalerRefundGatewayManager($driver));

    foreach ($driver->results as $index => $_) {
        $refund = talerVerificationRefund([
            'uuid'                 => 'refund-rejected-' . $index,
            'gateway_reference_id' => 'order-rejected-' . $index,
            'amount'               => 300,
            'raw_response'         => ['order_id' => 'order-rejected-' . $index],
        ]);
        expect($service->verifyRefund($refund, $gateway)['status'])->toBe('pending');
    }
});

test('verification errors are persisted for invalid gateways orders drivers and provider failures', function () {
    $refund  = talerVerificationRefund(['gateway_reference_id' => null, 'raw_response' => []]);
    $service = new TalerRefundVerificationService(new TalerRefundGatewayManager(new TalerRefundStatusDriver()));

    expect($service->verifyRefund($refund)['status'])->toBe('error')
        ->and(data_get($refund->refresh()->raw_response, 'refund_verification.error'))->toContain('not attached');

    $stripe = talerVerificationGateway('gateway-stripe', 'company-taler', 'stripe');
    expect($service->verifyRefund($refund, $stripe)['message'])->toContain('not attached');

    $taler = talerVerificationGateway();
    expect($service->verifyRefund($refund, $taler)['message'])->toContain('resolve original');

    $refund->gateway_reference_id = 'order-error';
    $refund->save();
    $unsupported = new TalerRefundVerificationService(new TalerRefundGatewayManager(new TalerRefundUnsupportedDriver()));
    expect($unsupported->verifyRefund($refund, $taler)['message'])->toContain('does not support');

    $driver            = new TalerRefundStatusDriver();
    $driver->exception = new RuntimeException('merchant backend unavailable');
    $failing           = new TalerRefundVerificationService(new TalerRefundGatewayManager($driver));
    expect($failing->verifyRefund($refund, $taler)['message'])->toBe('merchant backend unavailable')
        ->and(LoggerManager::$records)->toHaveCount(1);
});

test('pending invoice state distinguishes partial refunds and unresolved invoice references', function () {
    $gateway = talerVerificationGateway();
    $invoice = talerVerificationInvoice([
        'status' => 'partial',
        'meta'   => ['refunded_amount' => 300],
    ]);
    $refund = talerVerificationRefund([
        'amount'               => 300,
        'raw_response'         => ['data' => ['invoice_uuid' => 'invoice-taler', 'order_id' => 'order-partial']],
        'gateway_reference_id' => 'order-partial',
    ]);
    $driver            = new TalerRefundStatusDriver();
    $driver->results[] = ['data' => ['refund_pending' => false, 'refund_taken' => 'USD:0']];
    $driver->results[] = ['data' => ['refund_pending' => false, 'refund_taken' => 'USD:0']];
    $service           = new TalerRefundVerificationService(new TalerRefundGatewayManager($driver));

    expect($service->verifyRefund($refund, $gateway)['status'])->toBe('pending');
    $invoice->refresh();
    expect($invoice->status)->toBe('partial_refund_pending')
        ->and(data_get($invoice->meta, 'pending_wallet_refund_amount'))->toBe(300);

    $orphan = talerVerificationRefund([
        'uuid'                 => 'refund-orphan',
        'gateway_reference_id' => 'order-orphan',
        'raw_response'         => ['data' => ['order_id' => 'order-orphan']],
    ]);
    expect($service->verifyRefund($orphan, $gateway)['status'])->toBe('pending');
});
