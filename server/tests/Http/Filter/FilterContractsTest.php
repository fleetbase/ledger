<?php

use Fleetbase\Ledger\Http\Filter\AccountFilter;
use Fleetbase\Ledger\Http\Filter\GatewayFilter;
use Fleetbase\Ledger\Http\Filter\GatewayTransactionFilter;
use Fleetbase\Ledger\Http\Filter\InvoiceFilter;
use Fleetbase\Ledger\Http\Filter\JournalFilter;
use Fleetbase\Ledger\Http\Filter\TransactionFilter;
use Fleetbase\Ledger\Http\Filter\WalletFilter;
use Fleetbase\Ledger\Models\Account;
use Fleetbase\Ledger\Models\Gateway;
use Fleetbase\Ledger\Models\GatewayTransaction;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Models\Journal;
use Fleetbase\Ledger\Models\Transaction;
use Fleetbase\Ledger\Models\Wallet;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

function filterContract(object $filter, Builder $builder): object
{
    $property = new ReflectionProperty(Fleetbase\Http\Filter\Filter::class, 'builder');
    $property->setAccessible(true);
    $property->setValue($filter, $builder);

    return $filter;
}

beforeEach(function () {
    $capsule = new Capsule(Container::getInstance());
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'testing');
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('testing');
    Container::getInstance()->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstance('db');
    Model::clearBootedModels();
    if (!Builder::hasGlobalMacro('searchWhere')) {
        Builder::macro('searchWhere', function (string $column, mixed $value) {
            return $this->where($column, 'like', '%' . $value . '%');
        });
    }
    session(['company' => 'company-filter-contract']);
});

test('account filters build tenant, search, classification, identifier, and date predicates', function () {
    $filter = filterContract(new AccountFilter(new Request()), Account::query());
    $filter->queryForInternal();
    $filter->query('cash');
    $filter->type('asset');
    $filter->status('active');
    $filter->code('1000');
    $filter->publicId('account_public');
    $filter->createdAt('2026-07-01,2026-07-31');

    $builder = (new ReflectionProperty(Fleetbase\Http\Filter\Filter::class, 'builder'))->getValue($filter);
    expect($builder->toSql())->toContain('"company_uuid" = ?')
        ->toContain('"type" = ?')
        ->toContain('"status" = ?')
        ->toContain('"created_at" between ? and ?');

    $public = filterContract(new AccountFilter(new Request()), Account::query());
    $public->queryForPublic();
    $public->createdAt('2026-07-15');
    expect((new ReflectionProperty(Fleetbase\Http\Filter\Filter::class, 'builder'))->getValue($public)->toSql())
        ->toContain('strftime');
});

test('gateway filters build driver, status, identifier, search, and both date shapes', function () {
    $filter = filterContract(new GatewayFilter(new Request()), Gateway::query());
    $filter->queryForInternal();
    $filter->query('taler');
    $filter->driver('taler');
    $filter->status('active');
    $filter->publicId('gateway_public');
    $filter->createdAt(['2026-07-01', '2026-07-31']);

    $builder = (new ReflectionProperty(Fleetbase\Http\Filter\Filter::class, 'builder'))->getValue($filter);
    expect($builder->toSql())->toContain('"driver" = ?')->toContain('"created_at" between ? and ?');

    $public = filterContract(new GatewayFilter(new Request()), Gateway::query());
    $public->queryForPublic();
    $public->createdAt('2026-07-10');
});

test('gateway transaction filters preserve eager loading and provider audit predicates', function () {
    $filter = filterContract(new GatewayTransactionFilter(new Request()), GatewayTransaction::query());
    $filter->queryForInternal();
    $filter->query('provider-reference');
    $filter->type('purchase');
    $filter->status('succeeded');
    $filter->gateway('gateway-uuid');
    $filter->publicId('gateway_transaction_public');
    $filter->createdAt('2026-07-01,2026-07-31');

    $builder = (new ReflectionProperty(Fleetbase\Http\Filter\Filter::class, 'builder'))->getValue($filter);
    expect($builder->getEagerLoads())->toHaveKey('gateway')
        ->and($builder->toSql())->toContain('"gateway_uuid" = ?');

    $public = filterContract(new GatewayTransactionFilter(new Request()), GatewayTransaction::query());
    $public->queryForPublic();
    $public->createdAt('2026-07-10');
});

test('invoice filters implement optional fields, aliases, amount bounds, order lookup, and dates', function () {
    $filter = filterContract(new InvoiceFilter(new Request()), Invoice::query());
    $filter->queryForInternal();
    $filter->query('INV-100');
    $filter->status(null);
    $filter->status('sent');
    $filter->currency(null);
    $filter->currency('usd');
    $filter->customer(null);
    $filter->customerUuid('customer-uuid');
    $filter->order(null);
    $filter->orderUuid('order_public');
    $filter->amount(null);
    $filter->amount('100,500');
    $filter->publicId('invoice_public');
    $filter->createdAt('2026-07-01,2026-07-31');
    $filter->dueDate('2026-08-01');

    $builder = (new ReflectionProperty(Fleetbase\Http\Filter\Filter::class, 'builder'))->getValue($filter);
    expect($builder->getEagerLoads())->toHaveKeys(['customer', 'items'])
        ->and($builder->toSql())->toContain('"total_amount" between ? and ?')
        ->toContain('"currency" = ?')
        ->and($builder->getBindings())->toContain('USD');

    foreach (['100,', ',500', 'invalid,500', '100,invalid', 'invalid'] as $amount) {
        $bounded = filterContract(new InvoiceFilter(new Request()), Invoice::query());
        $bounded->amount($amount);
    }

    $public = filterContract(new InvoiceFilter(new Request()), Invoice::query());
    $public->queryForPublic();
    $public->createdAt('2026-07-10');
    $public->dueDate('2026-08-01,2026-08-31');
});

test('journal filters build tenant relationship, account, status, identifier, and date predicates', function () {
    $filter = filterContract(new JournalFilter(new Request()), Journal::query());
    $filter->queryForInternal();
    $filter->query('opening');
    $filter->type('manual_entry');
    $filter->status('posted');
    $filter->debitAccount('debit-uuid');
    $filter->creditAccount('credit-uuid');
    $filter->publicId('journal_public');
    $filter->createdAt('2026-07-01,2026-07-31');

    $builder = (new ReflectionProperty(Fleetbase\Http\Filter\Filter::class, 'builder'))->getValue($filter);
    expect($builder->getEagerLoads())->toHaveKeys(['debitAccount', 'creditAccount'])
        ->and($builder->toSql())->toContain('"debit_account_uuid" = ?');

    $public = filterContract(new JournalFilter(new Request()), Journal::query());
    $public->queryForPublic();
    $public->createdAt('2026-07-10');
});

test('transaction filters build every lifecycle, actor, reference, and payment predicate', function () {
    $filter = filterContract(new TransactionFilter(new Request()), Transaction::query());
    $filter->queryForInternal();
    $filter->query('reference');
    $filter->type('payment');
    $filter->direction('credit');
    $filter->status('completed');
    $filter->settlementStatus('settled');
    $filter->gateway('taler');
    $filter->customer('customer-uuid');
    $filter->payer('payer-uuid');
    $filter->subject('subject-uuid');
    $filter->context('context-uuid');
    $filter->reference('external-ref');
    $filter->paymentMethod('wallet');
    $filter->publicId('transaction_public');
    $filter->createdAt('2026-07-01,2026-07-31');

    $builder = (new ReflectionProperty(Fleetbase\Http\Filter\Filter::class, 'builder'))->getValue($filter);
    expect($builder->getEagerLoads())->toHaveKeys([
        'items', 'journal', 'journal.debitAccount', 'journal.creditAccount',
        'subject', 'payer', 'payee', 'initiator', 'context',
    ])->and($builder->toSql())->toContain('"settlement_status" = ?')
        ->toContain('"payment_method" = ?');

    $public = filterContract(new TransactionFilter(new Request()), Transaction::query());
    $public->queryForPublic();
    $public->createdAt('2026-07-10');
});

test('wallet filters translate computed types and boolean frozen state into stored predicates', function () {
    $filter = filterContract(new WalletFilter(new Request()), Wallet::query());
    $filter->queryForInternal();
    $filter->query('operating');
    $filter->type(null);
    $filter->type('Driver');
    $filter->status('active');
    $filter->currency('mnt');
    $filter->isFrozen(null);
    $filter->isFrozen('true');
    $filter->subject('subject-uuid');
    $filter->subjectType(null);
    $filter->subjectType('Company');
    $filter->publicId('wallet_public');
    $filter->createdAt('2026-07-01,2026-07-31');

    $builder = (new ReflectionProperty(Fleetbase\Http\Filter\Filter::class, 'builder'))->getValue($filter);
    expect($builder->getEagerLoads())->toHaveKey('subject')
        ->and($builder->toSql())->toContain('"subject_type" like ?')
        ->toContain('"is_frozen" = ?')
        ->and($builder->getBindings())->toContain('MNT', true);

    $public = filterContract(new WalletFilter(new Request()), Wallet::query());
    $public->queryForPublic();
    $public->createdAt('2026-07-10');
});
