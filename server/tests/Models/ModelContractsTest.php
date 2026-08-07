<?php

use Fleetbase\Ledger\Models\Account;
use Fleetbase\Ledger\Models\Gateway;
use Fleetbase\Ledger\Models\GatewayTransaction;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Models\InvoiceItem;
use Fleetbase\Ledger\Models\Journal;
use Fleetbase\Ledger\Models\Transaction;
use Fleetbase\Ledger\Models\Wallet;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class WalletStateProbe extends Wallet
{
    public function update(array $attributes = [], array $options = []): bool
    {
        $this->fill($attributes);

        return true;
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        $this->{$column} += $amount;

        return 1;
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        $this->{$column} -= $amount;

        return 1;
    }

    public function refresh()
    {
        return $this;
    }
}

final class AccountBalanceProbe extends Account
{
    public bool $saved = false;

    public function save(array $options = [])
    {
        $this->saved = true;

        return true;
    }
}

final class ModelScopeQuery
{
    public array $wheres = [];

    public function where(...$arguments): self
    {
        $this->wheres[] = $arguments;

        return $this;
    }
}

beforeEach(function () {
    $capsule = new Capsule(Container::getInstance());
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'testing');
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('testing');
    Container::getInstance()->instance('db', $capsule->getDatabaseManager());
});

test('ledger transaction exposes all accounting and polymorphic relationships', function () {
    $transaction = new Transaction(['direction' => 'credit', 'reference' => 'ref-1']);

    expect($transaction->isFillable('direction'))->toBeTrue()
        ->and($transaction->isFillable('reference'))->toBeTrue()
        ->and($transaction->journal())->toBeInstanceOf(HasOne::class)
        ->and($transaction->items())->toBeInstanceOf(HasMany::class)
        ->and($transaction->subject())->toBeInstanceOf(MorphTo::class)
        ->and($transaction->payer())->toBeInstanceOf(MorphTo::class)
        ->and($transaction->payee())->toBeInstanceOf(MorphTo::class)
        ->and($transaction->initiator())->toBeInstanceOf(MorphTo::class)
        ->and($transaction->context())->toBeInstanceOf(MorphTo::class);
});

test('gateway transactions expose relationships scopes and idempotency state', function () {
    $transaction = new GatewayTransaction(['processed_at' => null]);
    $query       = new ModelScopeQuery();

    expect($transaction->gateway())->toBeInstanceOf(BelongsTo::class)
        ->and($transaction->transaction())->toBeInstanceOf(BelongsTo::class)
        ->and($transaction->scopeForGatewayReference($query, 'ref-1'))->toBe($query)
        ->and($transaction->scopeOfType($query, 'refund'))->toBe($query)
        ->and($transaction->scopeWithStatus($query, 'pending'))->toBe($query)
        ->and($transaction->isProcessed())->toBeFalse();

    $transaction->processed_at = now();
    expect($transaction->isProcessed())->toBeTrue();
});

test('gateway models protect credentials and expose operational helpers', function () {
    $gateway = new Gateway([
        'status'       => 'active',
        'capabilities' => ['purchase', 'refund'],
    ]);
    $query = new ModelScopeQuery();

    expect($gateway->transactions())->toBeInstanceOf(HasMany::class)
        ->and($gateway->decryptedConfig())->toBe([])
        ->and($gateway->hasCapability('refund'))->toBeTrue()
        ->and($gateway->hasCapability('setup'))->toBeFalse()
        ->and($gateway->isActive())->toBeTrue()
        ->and($gateway->scopeActive($query))->toBe($query)
        ->and($gateway->scopeForDriver($query, 'stripe'))->toBe($query)
        ->and($gateway->scopeForCompany($query, 'company-1'))->toBe($query)
        ->and($gateway->toArray())->not->toHaveKey('config');
});

test('wallet relationships computed values and state rules cover every owner type', function () {
    $wallet = new WalletStateProbe(['balance' => 1050, 'status' => Wallet::STATUS_ACTIVE]);

    expect($wallet->subject())->toBeInstanceOf(MorphTo::class)
        ->and($wallet->transactions())->toBeInstanceOf(HasMany::class)
        ->and($wallet->completedTransactions())->toBeInstanceOf(HasMany::class)
        ->and($wallet->credits())->toBeInstanceOf(HasMany::class)
        ->and($wallet->debits())->toBeInstanceOf(HasMany::class)
        ->and($wallet->type)->toBe('unknown')
        ->and($wallet->formatted_balance)->toBe('10.50')
        ->and($wallet->isActive())->toBeTrue()
        ->and($wallet->isFrozen())->toBeFalse()
        ->and($wallet->isClosed())->toBeFalse()
        ->and($wallet->canDebit())->toBeTrue()
        ->and($wallet->canCredit())->toBeTrue()
        ->and($wallet->hasSufficientBalance(1050))->toBeTrue();

    foreach ([
        'App\\DriverProfile' => 'driver',
        'App\\Customer'      => 'customer',
        'App\\Company'       => 'company',
        'App\\User'          => 'user',
        'App\\Vendor'        => 'vendor',
    ] as $subjectType => $expected) {
        $wallet->subject_type = $subjectType;
        expect($wallet->type)->toBe($expected);
    }
});

test('wallet state transitions and balance operations preserve safety invariants', function () {
    $wallet = new WalletStateProbe(['balance' => 1000, 'status' => Wallet::STATUS_ACTIVE]);

    $wallet->freeze();
    expect($wallet->is_frozen)->toBeTrue()
        ->and($wallet->canDebit())->toBeFalse()
        ->and($wallet->canCredit())->toBeTrue();

    $wallet->activate();
    expect($wallet->status)->toBe(Wallet::STATUS_ACTIVE)
        ->and($wallet->credit(250))->toBe(1250)
        ->and($wallet->debit(500))->toBe(750);

    expect(fn () => $wallet->debit(751))->toThrow(RuntimeException::class, 'Insufficient wallet balance');

    $wallet->close();
    expect($wallet->isClosed())->toBeTrue()
        ->and($wallet->canCredit())->toBeFalse();
});

test('accounts calculate normal balances classify types and update cached values', function () {
    Capsule::schema('testing')->create('ledger_journals', function ($table) {
        $table->increments('id');
        $table->string('debit_account_uuid')->nullable();
        $table->string('credit_account_uuid')->nullable();
        $table->integer('amount');
        $table->softDeletes();
    });
    Capsule::table('ledger_journals')->insert([
        ['debit_account_uuid' => 'account-1', 'credit_account_uuid' => null, 'amount' => 1200],
        ['debit_account_uuid' => null, 'credit_account_uuid' => 'account-1', 'amount' => 300],
    ]);

    $account = new AccountBalanceProbe(['type' => 'asset', 'balance' => 0]);
    $account->setAttribute('uuid', 'account-1');

    expect($account->calculateBalance())->toBe(900)
        ->and($account->isAsset())->toBeTrue()
        ->and($account->isLiability())->toBeFalse();

    $account->updateBalance();
    expect($account->balance)->toBe(900)
        ->and($account->saved)->toBeTrue();

    foreach ([
        'liability' => 'isLiability',
        'equity'    => 'isEquity',
        'revenue'   => 'isRevenue',
        'expense'   => 'isExpense',
    ] as $type => $method) {
        $account->type = $type;
        expect($account->{$method}())->toBeTrue();
    }

    $account->type = 'revenue';
    expect($account->calculateBalance())->toBe(-900);
});

test('invoice item and invoice relationships retain calculation and status contracts', function () {
    $item = new InvoiceItem(['quantity' => 3, 'unit_price' => 200, 'tax_rate' => 10]);
    $item->calculateAmount();

    expect($item->amount)->toBe(600)
        ->and($item->tax_amount)->toBe(60)
        ->and($item->invoice())->toBeInstanceOf(BelongsTo::class);

    $invoice = new Invoice([
        'status'       => 'sent',
        'due_date'     => now()->subDay(),
        'total_amount' => 1000,
        'amount_paid'  => 0,
    ]);
    expect($invoice->customer())->toBeInstanceOf(MorphTo::class)
        ->and($invoice->order())->toBeInstanceOf(BelongsTo::class)
        ->and($invoice->transaction())->toBeInstanceOf(BelongsTo::class)
        ->and($invoice->template())->toBeInstanceOf(BelongsTo::class)
        ->and($invoice->items())->toBeInstanceOf(HasMany::class)
        ->and($invoice->isOverdue())->toBeTrue()
        ->and($invoice->isPaid())->toBeFalse();
});

test('journal relationships expose the complete double entry graph', function () {
    $journal = new Journal();

    expect($journal->transaction())->toBeInstanceOf(BelongsTo::class)
        ->and($journal->debitAccount())->toBeInstanceOf(BelongsTo::class)
        ->and($journal->creditAccount())->toBeInstanceOf(BelongsTo::class);
});
