<?php

use Fleetbase\Ledger\DTO\GatewayResponse;
use Fleetbase\Ledger\DTO\PurchaseRequest;
use Fleetbase\Ledger\DTO\RefundRequest;
use Fleetbase\Ledger\Events\PaymentFailed;
use Fleetbase\Ledger\Events\PaymentSucceeded;
use Fleetbase\Ledger\Events\RefundProcessed;
use Fleetbase\Ledger\Gateways\CashDriver;
use Fleetbase\Ledger\Models\Gateway;
use Fleetbase\Ledger\Models\GatewayTransaction;
use Fleetbase\Ledger\PaymentGatewayManager;
use Fleetbase\Ledger\Services\PaymentService;
use Fleetbase\TestSupport\EventRecorder;
use Fleetbase\TestSupport\LoggerManager;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

function bootPaymentDatabase(): void
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
        $table->string('company_uuid')->nullable();
        $table->string('created_by_uuid')->nullable();
        $table->string('name');
        $table->string('driver');
        $table->text('description')->nullable();
        $table->text('config')->nullable();
        $table->text('capabilities')->nullable();
        $table->text('meta')->nullable();
        $table->boolean('is_sandbox')->default(false);
        $table->string('environment')->nullable();
        $table->string('status')->default('active');
        $table->string('return_url')->nullable();
        $table->string('webhook_url')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('ledger_gateway_transactions', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('company_uuid')->nullable();
        $table->string('gateway_uuid')->nullable();
        $table->string('transaction_uuid')->nullable();
        $table->string('gateway_reference_id')->nullable();
        $table->string('type');
        $table->string('event_type')->nullable();
        $table->unsignedBigInteger('amount')->nullable();
        $table->string('currency')->nullable();
        $table->string('status')->default('pending');
        $table->text('message')->nullable();
        $table->text('raw_response')->nullable();
        $table->timestamp('processed_at')->nullable();
        $table->string('reconciliation_status')->nullable();
        $table->timestamp('reconciliation_checked_at')->nullable();
        $table->text('reconciliation_data')->nullable();
        $table->string('refund_status')->nullable();
        $table->timestamp('refund_accepted_at')->nullable();
        $table->timestamp('refund_expires_at')->nullable();
        $table->softDeletes();
        $table->timestamps();
        $table->unique(
            ['gateway_reference_id', 'type', 'event_type'],
            'unique_gateway_ref_type_event'
        );
    });
}

function createPaymentGateway(string $driver = 'cash', array $attributes = []): Gateway
{
    static $sequence = 0;
    $sequence++;

    return Gateway::create(array_merge([
        'uuid'         => sprintf('00000000-0000-4000-8000-%012d', $sequence),
        'public_id'    => 'gateway_test_' . $sequence,
        'company_uuid' => 'company-test',
        'name'         => ucfirst($driver) . ' Gateway',
        'driver'       => $driver,
        'is_sandbox'   => true,
        'environment'  => 'sandbox',
        'status'       => 'active',
    ], $attributes));
}

function paymentManager(): PaymentGatewayManager
{
    return new PaymentGatewayManager(Container::getInstance());
}

beforeEach(function () {
    bootPaymentDatabase();
    session(['company' => 'company-test']);
    EventRecorder::reset();
    LoggerManager::$records = [];
});

test('charge persists a successful gateway transaction and dispatches payment succeeded', function () {
    $gateway = createPaymentGateway();
    $service = new PaymentService(paymentManager());
    $request = new PurchaseRequest(
        amount: 4250,
        currency: 'USD',
        description: 'Invoice INV-4250',
        invoiceUuid: 'invoice-4250',
    );

    $response = $service->charge($gateway->public_id, $request);
    $audit    = GatewayTransaction::query()->first();

    expect($audit)->not->toBeNull()
        ->and($response->isSuccessful())->toBeTrue()
        ->and($audit->gateway_uuid)->toBe($gateway->uuid)
        ->and($audit->gateway_reference_id)->toStartWith('cash_')
        ->and($audit->type)->toBe('purchase')
        ->and($audit->event_type)->toBe(GatewayResponse::EVENT_PAYMENT_SUCCEEDED)
        ->and($audit->amount)->toBe(4250)
        ->and($audit->raw_response['invoice_uuid'])->toBe('invoice-4250')
        ->and(EventRecorder::$events)->toHaveCount(1)
        ->and(EventRecorder::$events[0])->toBeInstanceOf(PaymentSucceeded::class)
        ->and(EventRecorder::$events[0]->gatewayTransaction->is($audit))->toBeTrue();
});

test('charge dispatches failures but leaves pending responses for webhooks', function () {
    $manager = paymentManager();
    $manager->extend('failed-test', fn () => new class extends CashDriver {
        public function purchase(PurchaseRequest $request): GatewayResponse
        {
            return GatewayResponse::failure(
                gatewayTransactionId: 'failed-payment',
                message: 'Provider declined payment.',
            );
        }
    });
    $manager->extend('pending-test', fn () => new class extends CashDriver {
        public function purchase(PurchaseRequest $request): GatewayResponse
        {
            return GatewayResponse::pending('pending-payment');
        }
    });

    $failedGateway  = createPaymentGateway('failed-test');
    $pendingGateway = createPaymentGateway('pending-test');
    $service        = new PaymentService($manager);
    $request        = new PurchaseRequest(1000, 'USD', 'Gateway state test');

    $failed  = $service->charge($failedGateway->uuid, $request);
    $pending = $service->charge($pendingGateway->uuid, $request);

    expect($failed->isFailed())->toBeTrue()
        ->and($pending->isPending())->toBeTrue()
        ->and(GatewayTransaction::query()->count())->toBe(2)
        ->and(EventRecorder::$events)->toHaveCount(1)
        ->and(EventRecorder::$events[0])->toBeInstanceOf(PaymentFailed::class);
});

test('refund persists provider metadata and dispatches refund processed', function () {
    $manager = paymentManager();
    $manager->extend('refund-test', fn () => new class extends CashDriver {
        public function refund(RefundRequest $request): GatewayResponse
        {
            return GatewayResponse::success(
                gatewayTransactionId: $request->gatewayTransactionId,
                eventType: GatewayResponse::EVENT_REFUND_PROCESSED,
                message: 'Refund approved.',
                amount: $request->amount,
                currency: $request->currency,
                rawResponse: ['provider_refund_id' => 'refund-1'],
                data: ['refund_status' => 'wallet_uri_returned'],
            );
        }
    });

    $gateway = createPaymentGateway('refund-test');
    $service = new PaymentService($manager);
    $request = new RefundRequest(
        gatewayTransactionId: 'original-payment',
        amount: 750,
        currency: 'USD',
        invoiceUuid: 'invoice-refund',
    );

    $response = $service->refund($gateway->uuid, $request);
    $audit    = GatewayTransaction::firstOrFail();

    expect($response->isSuccessful())->toBeTrue()
        ->and($audit->type)->toBe('refund')
        ->and($audit->refund_status)->toBe('wallet_uri_returned')
        ->and($audit->raw_response['original_gateway_reference_id'])->toBe('original-payment')
        ->and($audit->raw_response['provider_refund_id'])->toBe('refund-1')
        ->and($audit->raw_response['invoice_uuid'])->toBe('invoice-refund')
        ->and(EventRecorder::$events[0])->toBeInstanceOf(RefundProcessed::class);
});

test('duplicate refund audit references are made unique without changing provider response', function () {
    $manager = paymentManager();
    $manager->extend('duplicate-refund-test', fn () => new class extends CashDriver {
        public function refund(RefundRequest $request): GatewayResponse
        {
            return GatewayResponse::success(
                gatewayTransactionId: 'same-provider-reference',
                eventType: GatewayResponse::EVENT_REFUND_PROCESSED,
                amount: $request->amount,
                currency: $request->currency,
            );
        }
    });

    $gateway = createPaymentGateway('duplicate-refund-test');
    $service = new PaymentService($manager);
    $request = new RefundRequest('original-payment', 100, 'USD');

    $first  = $service->refund($gateway->uuid, $request);
    $second = $service->refund($gateway->uuid, $request);
    $refs   = GatewayTransaction::orderBy('created_at')->pluck('gateway_reference_id')->all();

    expect($first->gatewayTransactionId)->toBe('same-provider-reference')
        ->and($second->gatewayTransactionId)->toBe('same-provider-reference')
        ->and($refs[0])->toBe('same-provider-reference')
        ->and($refs[1])->toStartWith('same-provider-reference-refund-')
        ->and($refs[1])->not->toBe($refs[0]);
});

test('failed refunds are audited without dispatching a processed event', function () {
    $manager = paymentManager();
    $manager->extend('failed-refund-test', fn () => new class extends CashDriver {
        public function refund(RefundRequest $request): GatewayResponse
        {
            return GatewayResponse::failure(
                gatewayTransactionId: $request->gatewayTransactionId,
                eventType: GatewayResponse::EVENT_REFUND_FAILED,
                message: 'Refund rejected.',
            );
        }
    });

    $gateway  = createPaymentGateway('failed-refund-test');
    $response = (new PaymentService($manager))->refund(
        $gateway->uuid,
        new RefundRequest('payment-failed-refund', 250, 'USD')
    );

    expect($response->isFailed())->toBeTrue()
        ->and(GatewayTransaction::first()->status)->toBe(GatewayResponse::STATUS_FAILED)
        ->and(EventRecorder::$events)->toBeEmpty();
});

test('a persistence outage is logged without changing a completed provider charge', function () {
    $manager = paymentManager();
    $manager->extend('persistence-outage-test', fn () => new class extends CashDriver {
        public function purchase(PurchaseRequest $request): GatewayResponse
        {
            Capsule::connection('testing')->statement('DROP TABLE ledger_gateway_transactions');

            return GatewayResponse::success(
                gatewayTransactionId: 'provider-charge-completed',
                eventType: GatewayResponse::EVENT_PAYMENT_SUCCEEDED,
                amount: $request->amount,
                currency: $request->currency,
            );
        }
    });

    $gateway  = createPaymentGateway('persistence-outage-test');
    $response = (new PaymentService($manager))->charge(
        $gateway->uuid,
        new PurchaseRequest(9900, 'USD', 'Persistence outage')
    );
    $error = LoggerManager::$records[array_key_last(LoggerManager::$records)];

    expect($response->isSuccessful())->toBeTrue()
        ->and($error['level'])->toBe('error')
        ->and($error['message'])->toBe('Failed to persist GatewayTransaction.')
        ->and($error['context']['gateway_reference_id'])->toBe('provider-charge-completed')
        ->and($error['context']['type'])->toBe('purchase')
        ->and($error['context']['error'])->toContain('ledger_gateway_transactions')
        ->and(EventRecorder::$events)->toHaveCount(1)
        ->and(EventRecorder::$events[0])->toBeInstanceOf(PaymentSucceeded::class)
        ->and(EventRecorder::$events[0]->gatewayTransaction->exists)->toBeFalse()
        ->and(EventRecorder::$events[0]->gatewayTransaction->gateway_reference_id)->toBe('provider-charge-completed');
});

test('payment method creation enforces capability before invoking a driver', function () {
    $cashGateway = createPaymentGateway();
    $manager     = paymentManager();
    $service     = new PaymentService($manager);

    $unsupported = $service->createPaymentMethod($cashGateway->uuid, ['token' => 'card']);

    expect($unsupported->isFailed())->toBeTrue()
        ->and($unsupported->message)->toContain('does not support payment method tokenization')
        ->and(GatewayTransaction::query()->count())->toBe(0);

    $manager->extend('token-test', fn () => new class extends CashDriver {
        public function getCapabilities(): array
        {
            return ['tokenization'];
        }

        public function createPaymentMethod(array $data): GatewayResponse
        {
            return GatewayResponse::success(
                gatewayTransactionId: 'payment-method-1',
                eventType: GatewayResponse::EVENT_SETUP_SUCCEEDED,
                data: ['token' => $data['token']],
            );
        }
    });
    $tokenGateway = createPaymentGateway('token-test');

    $created = $service->createPaymentMethod($tokenGateway->uuid, ['token' => 'tok_1']);

    expect($created->isSuccessful())->toBeTrue()
        ->and($created->data['token'])->toBe('tok_1')
        ->and(GatewayTransaction::first()->type)->toBe('setup_intent')
        ->and(GatewayTransaction::first()->event_type)->toBe(GatewayResponse::EVENT_SETUP_SUCCEEDED);
});

test('gateway lookup is company scoped and requires an active configuration', function () {
    $gateway = createPaymentGateway('cash', [
        'company_uuid' => 'other-company',
    ]);

    expect(fn () => (new PaymentService(paymentManager()))->charge(
        $gateway->uuid,
        new PurchaseRequest(100, 'USD', 'Wrong tenant')
    ))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);

    session(['company' => null]);
    $gateway->status = 'inactive';
    $gateway->save();

    expect(fn () => (new PaymentService(paymentManager()))->charge(
        $gateway->uuid,
        new PurchaseRequest(100, 'USD', 'Inactive gateway')
    ))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

test('driver manifest delegates to the gateway manager', function () {
    $manager = paymentManager();

    expect((new PaymentService($manager))->getDriverManifest())
        ->toBe($manager->getDriverManifest());
});
