<?php

use Fleetbase\Ledger\Console\Commands\UpdateOverdueInvoices;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Console\Tester\CommandTester;

function bootUpdateOverdueInvoicesDatabase(): void
{
    $capsule = new Capsule(Container::getInstance());
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'testing');
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('testing');
    Container::getInstance()->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstance('db');
    Model::clearBootedModels();

    $schema = $capsule->getConnection('testing')->getSchemaBuilder();
    $schema->create('ledger_invoices', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('number')->nullable();
        $table->dateTime('due_date')->nullable();
        $table->string('status');
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('ledger_invoice_items', function (Blueprint $table) {
        $table->increments('id');
        $table->string('invoice_uuid');
        $table->softDeletes();
    });
}

function updateOverdueInvoicesTester(): CommandTester
{
    $command = new UpdateOverdueInvoices();
    $command->setLaravel(Container::getInstance());

    return new CommandTester($command);
}

beforeEach(function () {
    bootUpdateOverdueInvoicesDatabase();
});

test('overdue invoice command reports when no eligible invoices exist', function () {
    Capsule::table('ledger_invoices')->insert([
        'uuid'     => 'future-invoice',
        'number'   => 'INV-FUTURE',
        'due_date' => now()->addDay(),
        'status'   => 'sent',
    ]);
    $tester = updateOverdueInvoicesTester();

    expect($tester->execute([]))->toBe(0)
        ->and($tester->getDisplay())->toContain('Checking for overdue invoices...')
        ->toContain('No overdue invoices found.');
});

test('overdue invoice command updates only past-due sent and viewed invoices', function () {
    Capsule::table('ledger_invoices')->insert([
        ['uuid' => 'sent-overdue', 'number' => 'INV-SENT', 'due_date' => now()->subDays(2), 'status' => 'sent'],
        ['uuid' => 'viewed-overdue', 'number' => 'INV-VIEWED', 'due_date' => now()->subDay(), 'status' => 'viewed'],
        ['uuid' => 'draft-overdue', 'number' => 'INV-DRAFT', 'due_date' => now()->subWeek(), 'status' => 'draft'],
        ['uuid' => 'future-sent', 'number' => 'INV-FUTURE', 'due_date' => now()->addDay(), 'status' => 'sent'],
    ]);
    $tester = updateOverdueInvoicesTester();

    expect($tester->execute([]))->toBe(0)
        ->and($tester->getDisplay())->toContain('Found 2 overdue invoices to update.')
        ->toContain('Updated invoice #INV-SENT to overdue.')
        ->toContain('Updated invoice #INV-VIEWED to overdue.')
        ->toContain('All overdue invoices have been updated.')
        ->and(Capsule::table('ledger_invoices')->orderBy('uuid')->pluck('status', 'uuid')->all())
        ->toBe([
            'draft-overdue'  => 'draft',
            'future-sent'    => 'sent',
            'sent-overdue'   => 'overdue',
            'viewed-overdue' => 'overdue',
        ]);
});
