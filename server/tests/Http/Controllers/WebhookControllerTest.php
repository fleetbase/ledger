<?php

use Fleetbase\Ledger\DTO\GatewayResponse;
use Fleetbase\Ledger\Events\PaymentFailed;
use Fleetbase\Ledger\Events\PaymentSucceeded;
use Fleetbase\Ledger\Events\RefundProcessed;
use Fleetbase\Ledger\Exceptions\WebhookSignatureException;
use Fleetbase\Ledger\Gateways\CashDriver;
use Fleetbase\Ledger\Http\Controllers\WebhookController;
use Fleetbase\Ledger\Models\Gateway;
use Fleetbase\Ledger\Models\GatewayTransaction;
use Fleetbase\Ledger\PaymentGatewayManager;
use Fleetbase\TestSupport\EventRecorder;
use Fleetbase\TestSupport\LoggerManager;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

class WebhookTestDriver extends CashDriver
{
    public function __construct(
        private readonly ?GatewayResponse $webhookResponse = null,
        private readonly ?Throwable $webhookException = null,
    ) {
    }

    public function handleWebhook(Request $request): GatewayResponse
    {
        if ($this->webhookException) {
            throw $this->webhookException;
        }

        return $this->webhookResponse;
    }
}

function bootWebhookDatabase(): void
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
        $table->string('name');
        $table->string('driver');
        $table->text('config')->nullable();
        $table->boolean('is_sandbox')->default(false);
        $table->string('environment')->nullable();
        $table->string('status')->default('active');
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('ledger_gateway_transactions', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('company_uuid')->nullable();
        $table->string('gateway_uuid')->nullable();
        $table->string('gateway_reference_id')->nullable();
        $table->string('type');
        $table->string('event_type')->nullable();
        $table->unsignedBigInteger('amount')->nullable();
        $table->string('currency')->nullable();
        $table->string('status')->default('pending');
        $table->text('message')->nullable();
        $table->text('raw_response')->nullable();
        $table->timestamp('processed_at')->nullable();
        $table->softDeletes();
        $table->timestamps();
        $table->unique(
            ['gateway_reference_id', 'type', 'event_type'],
            'unique_gateway_ref_type_event'
        );
    });
}

function createWebhookGateway(string $driver, string $company = 'company-webhook'): Gateway
{
    static $sequence = 0;
    $sequence++;

    return Gateway::create([
        'uuid'         => sprintf('10000000-0000-4000-8000-%012d', $sequence),
        'public_id'    => 'gateway_webhook_' . $sequence,
        'company_uuid' => $company,
        'name'         => ucfirst($driver) . ' Gateway',
        'driver'       => $driver,
        'is_sandbox'   => true,
        'environment'  => 'sandbox',
        'status'       => 'active',
    ]);
}

function webhookController(string $driver, WebhookTestDriver $instance): WebhookController
{
    $manager = new PaymentGatewayManager(Container::getInstance());
    $manager->extend($driver, fn () => $instance);

    return new WebhookController($manager);
}

function webhookRequest(array $payload = [], array $headers = []): Request
{
    return Request::create('/ledger/webhooks/test', 'POST', $payload, [], [], array_combine(
        array_map(fn ($key) => 'HTTP_' . strtoupper(str_replace('-', '_', $key)), array_keys($headers)),
        array_values($headers)
    ) ?: []);
}

beforeEach(function () {
    bootWebhookDatabase();
    EventRecorder::reset();
    LoggerManager::$records = [];
});

test('unknown gateways acknowledge delivery without dispatching an event', function () {
    $controller = webhookController('missing-provider', new WebhookTestDriver());
    $response   = $controller->handle(webhookRequest(), 'missing-provider');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['message'])->toBe('Gateway not configured.')
        ->and(EventRecorder::$events)->toBeEmpty()
        ->and(LoggerManager::$records[array_key_last(LoggerManager::$records)]['level'])->toBe('warning');
});

test('unresolved and ambiguous taler webhooks are audited for investigation', function () {
    $controller = webhookController('taler', new WebhookTestDriver());
    $missing    = $controller->handle(webhookRequest(['payload' => 'unresolved']), 'taler');

    expect($missing->getData(true)['message'])->toContain('could not resolve')
        ->and(GatewayTransaction::query()->count())->toBe(1)
        ->and(GatewayTransaction::query()->first()->gateway_reference_id)->toStartWith('unresolved-');

    createWebhookGateway('taler');
    createWebhookGateway('taler');
    $ambiguous = $controller->handle(webhookRequest(['order_id' => 'taler-order-ambiguous']), 'taler');

    expect($ambiguous->getData(true)['message'])->toContain('matched multiple')
        ->and(GatewayTransaction::query()->where('gateway_reference_id', 'taler-order-ambiguous')->exists())->toBeTrue();
});

test('signature and driver failures return provider-safe response contracts', function () {
    $gateway = createWebhookGateway('signature-test');
    $request = webhookRequest([], ['X-Gateway-ID' => $gateway->public_id]);

    $signature = webhookController(
        'signature-test',
        new WebhookTestDriver(webhookException: new WebhookSignatureException('signature-test', 'Bad signature.'))
    )->handle($request, 'signature-test');

    expect($signature->getStatusCode())->toBe(400)
        ->and($signature->getData(true)['message'])->toBe('Signature verification failed.');

    $exception = webhookController(
        'signature-test',
        new WebhookTestDriver(webhookException: new RuntimeException('Provider parser failed.'))
    )->handle($request, 'signature-test');

    expect($exception->getStatusCode())->toBe(200)
        ->and($exception->getData(true)['message'])->toBe('Webhook processing error.');
});

test('normalized webhook events are persisted and dispatched by contract', function () {
    $cases = [
        GatewayResponse::EVENT_PAYMENT_SUCCEEDED  => PaymentSucceeded::class,
        GatewayResponse::EVENT_PAYMENT_FAILED     => PaymentFailed::class,
        GatewayResponse::EVENT_REFUND_PROCESSED   => RefundProcessed::class,
        'provider.informational'                  => null,
    ];

    foreach ($cases as $eventType => $expectedEvent) {
        $driver  = 'event-' . str_replace('.', '-', $eventType);
        $gateway = createWebhookGateway($driver);
        $normal  = GatewayResponse::success(
            gatewayTransactionId: 'reference-' . $eventType,
            eventType: $eventType,
            amount: 1500,
            currency: 'USD',
            rawResponse: ['provider_event' => $eventType],
        );
        EventRecorder::reset();

        $response = webhookController($driver, new WebhookTestDriver($normal))
            ->handle(webhookRequest(['company_uuid' => $gateway->company_uuid, 'gateway_uuid' => $gateway->uuid]), $driver);
        $audit = GatewayTransaction::query()->where('gateway_reference_id', 'reference-' . $eventType)->firstOrFail();

        expect($response->getData(true)['message'])->toBe('Webhook received.')
            ->and($audit->gateway_uuid)->toBe($gateway->uuid)
            ->and($audit->event_type)->toBe($eventType)
            ->and($audit->raw_response['provider_event'])->toBe($eventType);

        if ($expectedEvent) {
            expect(EventRecorder::$events)->toHaveCount(1)
                ->and(EventRecorder::$events[0])->toBeInstanceOf($expectedEvent);
        } else {
            expect(EventRecorder::$events)->toBeEmpty();
        }
    }
});

test('duplicate deliveries return already processed without a second dispatch', function () {
    $driver   = 'duplicate-webhook';
    $gateway  = createWebhookGateway($driver);
    $normal   = GatewayResponse::success(
        gatewayTransactionId: 'duplicate-reference',
        eventType: GatewayResponse::EVENT_PAYMENT_SUCCEEDED,
    );
    $handler  = webhookController($driver, new WebhookTestDriver($normal));
    $request  = webhookRequest(['gateway_public_id' => $gateway->public_id]);

    $first  = $handler->handle($request, $driver);
    $second = $handler->handle($request, $driver);

    expect($first->getData(true)['message'])->toBe('Webhook received.')
        ->and($second->getData(true)['message'])->toBe('Already processed.')
        ->and(GatewayTransaction::query()->count())->toBe(1)
        ->and(EventRecorder::$events)->toHaveCount(1);
});

test('a concurrent unique-key loser is safely treated as a duplicate', function () {
    $driver  = 'race-webhook';
    $gateway = createWebhookGateway($driver);
    $normal  = GatewayResponse::success(
        gatewayTransactionId: 'race-reference',
        eventType: GatewayResponse::EVENT_PAYMENT_SUCCEEDED,
    );
    $armed = true;
    GatewayTransaction::creating(function (GatewayTransaction $transaction) use (&$armed) {
        if (!$armed || $transaction->gateway_reference_id !== 'race-reference') {
            return;
        }

        $armed = false;
        Capsule::table('ledger_gateway_transactions')->insert([
            'uuid'                 => 'concurrent-race-winner',
            'public_id'            => 'gtxn_concurrent_race',
            'company_uuid'         => 'company-webhook',
            'gateway_uuid'         => null,
            'gateway_reference_id' => 'race-reference',
            'type'                 => 'webhook_event',
            'event_type'           => GatewayResponse::EVENT_PAYMENT_SUCCEEDED,
            'status'               => GatewayResponse::STATUS_SUCCEEDED,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);
    });

    $response = webhookController($driver, new WebhookTestDriver($normal))
        ->handle(webhookRequest(['gateway_id' => $gateway->uuid]), $driver);

    expect($response->getData(true)['message'])->toBe('Already processed.')
        ->and(EventRecorder::$events)->toBeEmpty()
        ->and(GatewayTransaction::query()->count())->toBe(1);
});
