<?php

use Fleetbase\Ledger\Console\Commands\BackfillTransactionDirection;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Console\Tester\CommandTester;

function bootBackfillTransactionDirectionDatabase(): void
{
    $capsule = new Capsule(Container::getInstance());
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'testing');
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('testing');
    Container::getInstance()->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstance('db');

    $capsule->getConnection('testing')->getSchemaBuilder()->create('transactions', function (Blueprint $table) {
        $table->increments('id');
        $table->string('type')->nullable();
        $table->string('direction')->nullable();
    });
}

beforeEach(function () {
    bootBackfillTransactionDirectionDatabase();
});

function backfillTransactionDirectionCommand(): BackfillTransactionDirection
{
    $command = new BackfillTransactionDirection();
    $command->setLaravel(Container::getInstance());

    return $command;
}

test('direction backfill reports when every transaction is already classified', function () {
    Capsule::table('transactions')->insert(['type' => 'deposit', 'direction' => 'credit']);
    $tester = new CommandTester(backfillTransactionDirectionCommand());

    expect($tester->execute([]))->toBe(0)
        ->and($tester->getDisplay())->toContain('All transactions already have a direction set.');
});

test('direction backfill classifies debit types and defaults all other types to credit in chunks', function () {
    Capsule::table('transactions')->insert([
        ['type' => 'REFUND', 'direction' => null],
        ['type' => 'payout', 'direction' => null],
        ['type' => 'deposit', 'direction' => null],
        ['type' => 'custom-adjustment', 'direction' => null],
        ['type' => null, 'direction' => null],
        ['type' => 'fee', 'direction' => 'credit'],
    ]);
    $tester = new CommandTester(backfillTransactionDirectionCommand());

    expect($tester->execute(['--chunk' => 2]))->toBe(0)
        ->and($tester->getDisplay())->toContain('Backfilling direction on 5 transaction(s)')
        ->toContain('Done — 5 transaction(s) updated.')
        ->and(Capsule::table('transactions')->orderBy('id')->pluck('direction')->all())
        ->toBe(['debit', 'debit', 'credit', 'credit', 'credit', 'credit']);
});
