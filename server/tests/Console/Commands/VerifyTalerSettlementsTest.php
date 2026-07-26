<?php

use Fleetbase\Ledger\Console\Commands\VerifyTalerSettlements;
use Fleetbase\Ledger\DTO\GatewayResponse;
use Fleetbase\Ledger\Gateways\CashDriver;
use Fleetbase\Ledger\Gateways\TalerDriver;
use Fleetbase\Ledger\PaymentGatewayManager;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Console\Tester\CommandTester;

final class SettlementTalerDriverSpy extends TalerDriver
{
    public array $responses  = [];
    public array $references = [];

    public function initialize(array $config, bool $sandbox = false): static
    {
        return $this;
    }

    public function fetchOrderStatus(string $orderId, array $query = []): array
    {
        $this->references[] = $orderId;
        $response           = $this->responses[$orderId] ?? [];

        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }
}

final class SettlementGatewayManagerSpy extends PaymentGatewayManager
{
    public object $resolvedDriver;

    public function __construct()
    {
        parent::__construct(Container::getInstance());
    }

    public function driver($driver = null)
    {
        return $this->resolvedDriver;
    }
}

function bootTalerSettlementDatabase(): void
{
    $capsule = new Capsule(Container::getInstance());
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'testing');
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('testing');
    Container::getInstance()->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstance('db');
    Model::clearBootedModels();

    $schema = Capsule::schema('testing');
    $schema->create('ledger_gateways', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid');
        $table->string('driver');
        $table->string('status');
        $table->boolean('is_sandbox')->default(false);
        $table->text('config')->nullable();
        $table->string('environment')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('ledger_gateway_transactions', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('gateway_uuid');
        $table->string('gateway_reference_id')->nullable();
        $table->string('event_type')->nullable();
        $table->string('reconciliation_status')->nullable();
        $table->dateTime('reconciliation_checked_at')->nullable();
        $table->text('reconciliation_data')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
}

function talerSettlementsTester(object $driver): CommandTester
{
    $manager                 = new SettlementGatewayManagerSpy();
    $manager->resolvedDriver = $driver;
    Container::getInstance()->instance(PaymentGatewayManager::class, $manager);
    $command = new VerifyTalerSettlements();
    $command->setLaravel(Container::getInstance());

    return new CommandTester($command);
}

function insertTalerGateway(array $overrides = []): void
{
    Capsule::table('ledger_gateways')->insert(array_merge([
        'uuid'         => 'gateway-1',
        'public_id'    => 'gateway_public_1',
        'company_uuid' => 'company-1',
        'driver'       => 'taler',
        'status'       => 'active',
        'is_sandbox'   => true,
    ], $overrides));
}

beforeEach(function () {
    bootTalerSettlementDatabase();
});

test('settlement verification reports filtered empty gateway state', function () {
    insertTalerGateway(['status' => 'inactive']);
    Capsule::table('ledger_gateways')->insert([
        'uuid'         => 'stripe-1',
        'public_id'    => 'stripe-public',
        'company_uuid' => 'company-1',
        'driver'       => 'stripe',
        'status'       => 'active',
    ]);
    $tester = talerSettlementsTester(new SettlementTalerDriverSpy());

    expect($tester->execute([
        '--company' => 'company-1',
        '--gateway' => 'gateway_public_1',
    ]))->toBe(0)
        ->and($tester->getDisplay())->toContain('No active Taler gateways found.');
});

test('settlement verification persists wired paid pending and provider error evidence', function () {
    insertTalerGateway();
    foreach ([
        ['uuid' => 'tx-wired', 'gateway_reference_id' => 'order-wired', 'event_type' => GatewayResponse::EVENT_PAYMENT_SUCCEEDED],
        ['uuid' => 'tx-paid', 'gateway_reference_id' => 'order-paid', 'event_type' => GatewayResponse::EVENT_PAYMENT_SUCCEEDED],
        ['uuid' => 'tx-pending', 'gateway_reference_id' => 'order-pending', 'event_type' => GatewayResponse::EVENT_PAYMENT_PENDING],
        ['uuid' => 'tx-error', 'gateway_reference_id' => 'order-error', 'event_type' => GatewayResponse::EVENT_PAYMENT_PENDING],
        ['uuid' => 'tx-failed', 'gateway_reference_id' => 'order-failed', 'event_type' => GatewayResponse::EVENT_PAYMENT_FAILED],
        ['uuid' => 'tx-missing', 'gateway_reference_id' => null, 'event_type' => GatewayResponse::EVENT_PAYMENT_PENDING],
    ] as $transaction) {
        Capsule::table('ledger_gateway_transactions')->insert(array_merge([
            'gateway_uuid' => 'gateway-1',
            'created_at'   => now(),
            'updated_at'   => now(),
        ], $transaction));
    }
    $driver            = new SettlementTalerDriverSpy();
    $driver->responses = [
        'order-wired' => [
            'http_status' => 200,
            'data'        => [
                'order_status'     => 'paid',
                'wired'            => true,
                'deposit_total'    => 'KUDOS:1',
                'wire_transfer_id' => 'wire-1',
            ],
        ],
        'order-paid' => [
            'http_status' => 200,
            'data'        => ['order_status' => 'paid', 'wired' => false, 'wire_transfer_subject' => 'subject-1'],
        ],
        'order-pending' => ['http_status' => 202, 'data' => ['order_status' => 'unpaid']],
        'order-error'   => new RuntimeException('backend offline'),
    ];
    $tester = talerSettlementsTester($driver);

    expect($tester->execute(['--limit' => 10]))->toBe(1)
        ->and($driver->references)->toBe(['order-wired', 'order-paid', 'order-pending', 'order-error'])
        ->and($tester->getDisplay())->toContain('Checked 3; errors 1.');

    $records = Capsule::table('ledger_gateway_transactions')
        ->whereIn('uuid', ['tx-wired', 'tx-paid', 'tx-pending', 'tx-error'])
        ->orderBy('uuid')
        ->get()
        ->keyBy('uuid');

    expect($records['tx-wired']->reconciliation_status)->toBe('wire_reconciled')
        ->and(json_decode($records['tx-wired']->reconciliation_data, true)['wire_transfer_id'])->toBe('wire-1')
        ->and($records['tx-paid']->reconciliation_status)->toBe('settlement_checked')
        ->and(json_decode($records['tx-paid']->reconciliation_data, true)['wire_transfer_id'])->toBe('subject-1')
        ->and($records['tx-pending']->reconciliation_status)->toBe('not_settled')
        ->and($records['tx-error']->reconciliation_status)->toBe('error')
        ->and(json_decode($records['tx-error']->reconciliation_data, true))->toBe(['error' => 'backend offline'])
        ->and($records['tx-error']->reconciliation_checked_at)->not->toBeNull();
});

test('settlement verification safely skips a misregistered driver without status support', function () {
    insertTalerGateway();
    Capsule::table('ledger_gateway_transactions')->insert([
        'uuid'                 => 'tx-1',
        'gateway_uuid'         => 'gateway-1',
        'gateway_reference_id' => 'order-1',
        'event_type'           => GatewayResponse::EVENT_PAYMENT_SUCCEEDED,
        'created_at'           => now(),
        'updated_at'           => now(),
    ]);
    $tester = talerSettlementsTester((new CashDriver())->initialize([], false));

    expect($tester->execute([]))->toBe(0)
        ->and($tester->getDisplay())->toContain('Checked 0; errors 0.');
});
