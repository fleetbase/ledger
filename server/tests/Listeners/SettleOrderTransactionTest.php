<?php

use Fleetbase\Ledger\Events\InvoicePaid;
use Fleetbase\Ledger\Listeners\SettleOrderTransaction;
use Fleetbase\Ledger\Models\Invoice;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

/** A transaction that records being saved, as core transactions do. */
class SettleOrderTransactionFake
{
    public int $saved = 0;

    public function __construct(public ?string $settlement_status = 'unpaid')
    {
    }

    public function save(): bool
    {
        return ++$this->saved > 0;
    }
}

class SettleOrderTransactionProbe extends SettleOrderTransaction
{
    public array $transactions      = [];
    public array $orderLookups      = [];
    public array $orderTransactions = [];

    protected function findTransaction(?string $uuid): ?object
    {
        return $uuid ? ($this->transactions[$uuid] ?? null) : null;
    }

    protected function orderTransactionUuid(?string $orderUuid): ?string
    {
        $this->orderLookups[] = $orderUuid;

        return $orderUuid ? ($this->orderTransactions[$orderUuid] ?? null) : null;
    }
}

function settleOrderTransactionEvent(?string $orderUuid, ?string $transactionUuid = null): InvoicePaid
{
    $invoice = new Invoice();
    $invoice->setRawAttributes(['uuid' => 'invoice-1', 'order_uuid' => $orderUuid, 'transaction_uuid' => $transactionUuid], true);

    return new InvoicePaid($invoice);
}

/** Real transactions and orders, so the listener's own lookups run against a database. */
function bootSettleOrderTransactionDatabase(): void
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
    Facade::clearResolvedInstance('db');

    $schema = $capsule->getConnection('testing')->getSchemaBuilder();
    $schema->create('transactions', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('settlement_status')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('orders', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('transaction_uuid')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    Capsule::table('transactions')->insert([
        ['uuid' => 'txn-linked', 'settlement_status' => 'unpaid'],
        ['uuid' => 'txn-order', 'settlement_status' => 'unpaid'],
        ['uuid' => 'txn-settled', 'settlement_status' => 'paid'],
    ]);
    Capsule::table('orders')->insert([
        ['uuid' => 'order-with-transaction', 'transaction_uuid' => 'txn-order'],
        ['uuid' => 'order-without-transaction', 'transaction_uuid' => null],
    ]);
}

/** @return array<string, ?string> */
function settleOrderTransactionStatuses(): array
{
    return Capsule::table('transactions')->orderBy('uuid')->pluck('settlement_status', 'uuid')->all();
}

test('a paid invoice settles the transaction it was raised for, once', function () {
    $listener                          = new SettleOrderTransactionProbe();
    $listener->transactions['txn-1']   = $transaction = new SettleOrderTransactionFake();

    expect($listener->handle(settleOrderTransactionEvent('order-1', 'txn-1')))->toBeTrue()
        ->and($transaction->settlement_status)->toBe('paid')
        ->and($listener->handle(settleOrderTransactionEvent('order-1', 'txn-1')))->toBeFalse()
        ->and($transaction->saved)->toBe(1)
        ->and($listener->orderLookups)->toBe([]);
});

test('an invoice without a transaction settles its order\'s transaction', function () {
    $listener                               = new SettleOrderTransactionProbe();
    $listener->orderTransactions['order-1'] = 'txn-order';
    $listener->transactions['txn-order']    = $transaction = new SettleOrderTransactionFake();

    expect($listener->handle(settleOrderTransactionEvent('order-1')))->toBeTrue()
        ->and($transaction->settlement_status)->toBe('paid');
});

test('invoices without an order or transaction, and missing transactions, are left alone', function () {
    $listener = new SettleOrderTransactionProbe();

    expect($listener->handle(settleOrderTransactionEvent('gone')))->toBeFalse()
        ->and($listener->handle(settleOrderTransactionEvent(null)))->toBeFalse()
        ->and($listener->handle(settleOrderTransactionEvent(null, 'txn-missing')))->toBeFalse();
});

test('the listener finds the invoice\'s own transaction in the database and settles it', function () {
    bootSettleOrderTransactionDatabase();

    expect((new SettleOrderTransaction())->handle(settleOrderTransactionEvent('order-with-transaction', 'txn-linked')))->toBeTrue()
        ->and(settleOrderTransactionStatuses())->toBe(['txn-linked' => 'paid', 'txn-order' => 'unpaid', 'txn-settled' => 'paid'])
        ->and((new SettleOrderTransaction())->handle(settleOrderTransactionEvent(null, 'txn-settled')))->toBeFalse()
        ->and((new SettleOrderTransaction())->handle(settleOrderTransactionEvent(null, 'txn-missing')))->toBeFalse();
});

test('the listener falls back to the transaction on the invoice\'s fleet-ops order', function () {
    bootSettleOrderTransactionDatabase();

    expect((new SettleOrderTransaction())->handle(settleOrderTransactionEvent('order-with-transaction')))->toBeTrue()
        ->and(settleOrderTransactionStatuses())->toBe(['txn-linked' => 'unpaid', 'txn-order' => 'paid', 'txn-settled' => 'paid'])
        ->and((new SettleOrderTransaction())->handle(settleOrderTransactionEvent('order-without-transaction')))->toBeFalse()
        ->and((new SettleOrderTransaction())->handle(settleOrderTransactionEvent('order-missing')))->toBeFalse()
        ->and((new SettleOrderTransaction())->handle(settleOrderTransactionEvent(null)))->toBeFalse()
        ->and(settleOrderTransactionStatuses())->toBe(['txn-linked' => 'unpaid', 'txn-order' => 'paid', 'txn-settled' => 'paid']);
});
