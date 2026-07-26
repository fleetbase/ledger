<?php

use Fleetbase\Ledger\Console\Commands\RepairRevenueLifecycle;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Services\RevenueLifecycleService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Console\Tester\CommandTester;

final class RepairRevenueLifecycleServiceSpy extends RevenueLifecycleService
{
    public array $orders   = [];
    public array $invoices = [];

    public function __construct()
    {
    }

    public function repairOrder($order, string $reason = 'repair'): void
    {
        $this->orders[] = [$order->uuid, $reason];
    }

    public function repairInvoice(Invoice $invoice, string $reason = 'repair'): void
    {
        $this->invoices[] = [$invoice->uuid, $reason];
    }
}

function bootRevenueRepairDatabase(): void
{
    $capsule = new Capsule(Container::getInstance());
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'testing');
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('testing');
    Container::getInstance()->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstance('db');
    Model::clearBootedModels();

    $connection = $capsule->getConnection('testing');
    $connection->getPdo()->sqliteCreateFunction('JSON_UNQUOTE', fn ($value) => $value, 1);
    $schema = $connection->getSchemaBuilder();
    $schema->create('orders', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('status')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('ledger_invoices', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('status')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('ledger_journals', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('type');
        $table->string('status');
        $table->text('meta')->nullable();
        $table->softDeletes();
    });
}

function repairRevenueLifecycleTester(RepairRevenueLifecycleServiceSpy $service): CommandTester
{
    $command = new RepairRevenueLifecycle($service);
    $command->setLaravel(Container::getInstance());

    return new CommandTester($command);
}

beforeEach(function () {
    bootRevenueRepairDatabase();
});

test('revenue lifecycle repair dry run audits categories without mutating records', function () {
    Capsule::table('orders')->insert([
        'uuid'      => 'order-canceled',
        'public_id' => 'order_public_1',
        'status'    => 'canceled',
    ]);
    Capsule::table('ledger_invoices')->insert([
        'uuid'      => 'invoice-void',
        'public_id' => 'invoice_public_1',
        'status'    => 'void',
    ]);
    $service = new RepairRevenueLifecycleServiceSpy();
    $tester  = repairRevenueLifecycleTester($service);

    expect($tester->execute(['--limit' => 0]))->toBe(0)
        ->and($service->orders)->toBe([])
        ->and($service->invoices)->toBe([])
        ->and($tester->getDisplay())
        ->toContain('Dry run: revenue lifecycle repair audit.')
        ->toContain('Inactive/deleted FleetOps orders found: 1')
        ->toContain('Sample: order_public_1')
        ->toContain('Deleted/void/cancelled invoices found: 1')
        ->toContain('Sample: invoice_public_1')
        ->toContain('journals missing reversals for inactive invoices: 0')
        ->toContain('Dry run complete.');
});

test('revenue lifecycle repair apply delegates bounded inactive records to the service', function () {
    Capsule::table('orders')->insert([
        ['uuid' => 'order-canceled', 'public_id' => 'order_public_1', 'status' => 'cancelled'],
        ['uuid' => 'order-active', 'public_id' => 'order_public_2', 'status' => 'active'],
    ]);
    Capsule::table('ledger_invoices')->insert([
        ['uuid' => 'invoice-deleted', 'public_id' => null, 'status' => 'sent', 'deleted_at' => now()],
        ['uuid' => 'invoice-active', 'public_id' => 'invoice_public_2', 'status' => 'sent', 'deleted_at' => null],
    ]);
    $service = new RepairRevenueLifecycleServiceSpy();
    $tester  = repairRevenueLifecycleTester($service);

    expect($tester->execute(['--apply' => true, '--limit' => 10]))->toBe(0)
        ->and($service->orders)->toBe([['order-canceled', 'repair_command']])
        ->and($service->invoices)->toBe([['invoice-deleted', 'repair_command']])
        ->and($tester->getDisplay())
        ->toContain('Applying revenue lifecycle repairs...')
        ->toContain('Sample: invoice-deleted')
        ->toContain('Repair run complete.');
});
