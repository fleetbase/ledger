<?php

use Fleetbase\Ledger\Http\Controllers\Internal\v1\AccountController;
use Fleetbase\Ledger\Http\Controllers\Internal\v1\JournalController;
use Fleetbase\Ledger\Http\Resources\v1\Account as AccountResource;
use Fleetbase\Ledger\Http\Resources\v1\Journal as JournalResource;
use Fleetbase\Ledger\Models\Account;
use Fleetbase\Ledger\Models\Journal;
use Fleetbase\Ledger\Services\LedgerService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;

class AccountingControllerRequest extends Request
{
    public function validate(array $rules, ...$params)
    {
        return $this->all();
    }
}

class AccountingControllerLedgerService extends LedgerService
{
    public array $calls = [];
    public Collection $generalLedger;

    public function __construct()
    {
        $this->generalLedger = collect();
    }

    public function getGeneralLedger(Account $account, ?string $startDate = null, ?string $endDate = null): Collection
    {
        $this->calls[] = ['getGeneralLedger', $account->uuid, $startDate, $endDate];

        return $this->generalLedger;
    }

    public function createJournalEntry(
        Account $debitAccount,
        Account $creditAccount,
        int $amount,
        string $description = '',
        array $options = [],
    ): Journal {
        $this->calls[] = ['createJournalEntry', $debitAccount->uuid, $creditAccount->uuid, $amount, $description, $options];

        return accountingControllerJournal([
            'uuid'                => 'manual-journal',
            'public_id'           => 'journal_manual',
            'debit_account_uuid'  => $debitAccount->uuid,
            'credit_account_uuid' => $creditAccount->uuid,
            'amount'              => $amount,
            'description'         => $description,
            'currency'            => $options['currency'],
            'entry_date'          => $options['entry_date'],
            'type'                => $options['type'],
        ]);
    }
}

function bootAccountingControllerDatabase(): void
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
    Container::getInstance()->instance('request', new Request());
    Facade::clearResolvedInstance('db');
    session(['company' => 'company-accounting-controller']);

    $schema = $capsule->getConnection('testing')->getSchemaBuilder();
    $schema->create('ledger_accounts', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('_key')->nullable();
        $table->string('company_uuid');
        $table->string('created_by_uuid')->nullable();
        $table->string('updated_by_uuid')->nullable();
        $table->string('name');
        $table->string('code');
        $table->string('type');
        $table->text('description')->nullable();
        $table->boolean('is_system_account')->default(false);
        $table->bigInteger('balance')->default(0);
        $table->string('currency')->default('USD');
        $table->string('status')->default('active');
        $table->text('meta')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('ledger_journals', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('_key')->nullable();
        $table->string('company_uuid');
        $table->string('transaction_uuid')->nullable();
        $table->string('debit_account_uuid');
        $table->string('credit_account_uuid');
        $table->string('number')->nullable();
        $table->string('type')->nullable();
        $table->string('status')->nullable();
        $table->string('reference')->nullable();
        $table->text('memo')->nullable();
        $table->boolean('is_system_entry')->default(false);
        $table->bigInteger('amount');
        $table->string('currency')->nullable();
        $table->text('description')->nullable();
        $table->date('entry_date')->nullable();
        $table->text('meta')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
}

function accountingControllerAccount(array $attributes = []): Account
{
    static $sequence = 0;
    $sequence++;
    $account = new Account();
    $account->forceFill(array_merge([
        'uuid'         => 'account-controller-' . $sequence,
        'public_id'    => 'account_public_' . $sequence,
        'company_uuid' => 'company-accounting-controller',
        'name'         => 'Cash',
        'code'         => 'CASH-' . $sequence,
        'type'         => 'asset',
        'balance'      => 0,
        'currency'     => 'USD',
        'status'       => 'active',
    ], $attributes));
    Account::withoutEvents(fn () => $account->save());

    return $account;
}

function accountingControllerJournal(array $attributes = []): Journal
{
    static $sequence = 0;
    $sequence++;
    $journal = new Journal();
    $journal->forceFill(array_merge([
        'uuid'                => 'journal-controller-' . $sequence,
        'public_id'           => 'journal_public_' . $sequence,
        'company_uuid'        => 'company-accounting-controller',
        'debit_account_uuid'  => 'debit-account',
        'credit_account_uuid' => 'credit-account',
        'number'              => 'JE-' . $sequence,
        'type'                => 'general',
        'status'              => 'posted',
        'amount'              => 100,
        'currency'            => 'USD',
        'entry_date'          => '2026-07-20',
    ], $attributes));
    Journal::withoutEvents(fn () => $journal->save());

    return $journal;
}

function accountingControllerJson(mixed $response): array
{
    return json_decode($response->getContent(), true);
}

beforeEach(function () {
    bootAccountingControllerDatabase();
});

test('account balance recalculation is tenant scoped and follows normal-balance rules', function () {
    $account = accountingControllerAccount(['uuid' => 'asset-account', 'public_id' => 'asset_public']);
    $other   = accountingControllerAccount(['uuid' => 'other-account']);
    accountingControllerJournal([
        'debit_account_uuid'  => $account->uuid,
        'credit_account_uuid' => $other->uuid,
        'amount'              => 900,
    ]);
    accountingControllerJournal([
        'debit_account_uuid'  => $other->uuid,
        'credit_account_uuid' => $account->uuid,
        'amount'              => 250,
    ]);

    $resource = (new AccountController())->recalculateBalance('asset_public', new Request());
    expect($resource->resource->balance)->toBe(650);

    accountingControllerAccount(['uuid' => 'foreign-account', 'company_uuid' => 'another-company']);
    expect(fn () => (new AccountController())->recalculateBalance('foreign-account', new Request()))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

test('general ledger returns debit-normal running balances and summary contracts', function () {
    $service = new AccountingControllerLedgerService();
    Container::getInstance()->instance(LedgerService::class, $service);
    $account                = accountingControllerAccount(['uuid' => 'ledger-asset', 'type' => 'asset']);
    $service->generalLedger = collect([
        (object) [
            'public_id'           => 'journal-debit',
            'entry_date'          => Carbon\Carbon::parse('2026-07-01'),
            'number'              => 'JE-1',
            'type'                => 'sale',
            'description'         => 'Debit entry',
            'memo'                => null,
            'reference'           => 'REF-1',
            'debit_account_uuid'  => $account->uuid,
            'credit_account_uuid' => 'other',
            'amount'              => 1000,
            'currency'            => 'USD',
            'is_system_entry'     => true,
        ],
        (object) [
            'public_id'           => 'journal-credit',
            'entry_date'          => null,
            'number'              => 'JE-2',
            'type'                => 'refund',
            'description'         => null,
            'memo'                => 'Credit memo',
            'reference'           => null,
            'debit_account_uuid'  => 'other',
            'credit_account_uuid' => $account->uuid,
            'amount'              => 300,
            'currency'            => null,
            'is_system_entry'     => false,
        ],
    ]);

    $payload = accountingControllerJson((new AccountController())->generalLedger(
        $account->uuid,
        Request::create('/ledger', 'GET', ['date_from' => '2026-07-01', 'date_to' => '2026-07-31'])
    ));
    expect($service->calls[0])->toBe(['getGeneralLedger', $account->uuid, '2026-07-01', '2026-07-31'])
        ->and($payload['entries'][0])->toMatchArray([
            'debit_amount'    => 1000,
            'credit_amount'   => 0,
            'running_balance' => 1000,
            'is_system_entry' => true,
        ])
        ->and($payload['entries'][1])->toMatchArray([
            'description'     => 'Credit memo',
            'debit_amount'    => 0,
            'credit_amount'   => 300,
            'running_balance' => 700,
            'currency'        => 'USD',
        ])
        ->and($payload['summary'])->toBe([
            'total_debits'  => 1000,
            'total_credits' => 300,
            'net_balance'   => 700,
            'currency'      => 'USD',
            'entry_count'   => 2,
        ]);
});

test('general ledger applies credit-normal running balance conventions', function () {
    $service = new AccountingControllerLedgerService();
    Container::getInstance()->instance(LedgerService::class, $service);
    $account                = accountingControllerAccount(['uuid' => 'revenue-account', 'type' => 'revenue']);
    $service->generalLedger = collect([
        (object) [
            'public_id'          => 'journal-credit', 'entry_date' => null, 'number' => null, 'type' => 'sale',
            'description'        => 'Revenue', 'memo' => null, 'reference' => null,
            'debit_account_uuid' => 'other', 'credit_account_uuid' => $account->uuid,
            'amount'             => 800, 'currency' => 'USD', 'is_system_entry' => false,
        ],
        (object) [
            'public_id'          => 'journal-debit', 'entry_date' => null, 'number' => null, 'type' => 'reversal',
            'description'        => 'Reversal', 'memo' => null, 'reference' => null,
            'debit_account_uuid' => $account->uuid, 'credit_account_uuid' => 'other',
            'amount'             => 150, 'currency' => 'USD', 'is_system_entry' => false,
        ],
    ]);

    $payload = accountingControllerJson((new AccountController())->generalLedger($account->uuid, new Request()));
    expect(array_column($payload['entries'], 'running_balance'))->toBe([800, 650])
        ->and($payload['summary']['net_balance'])->toBe(650);
});

test('manual journals enforce tenant accounts and delegate the full accounting contract', function () {
    $service = new AccountingControllerLedgerService();
    Container::getInstance()->instance(LedgerService::class, $service);
    $debit   = accountingControllerAccount(['uuid' => 'debit-account']);
    $credit  = accountingControllerAccount(['uuid' => 'credit-account']);
    $request = AccountingControllerRequest::create('/journals', 'POST', [
        'debit_account_uuid'  => $debit->uuid,
        'credit_account_uuid' => $credit->uuid,
        'amount'              => 450,
        'currency'            => 'MNT',
        'description'         => 'Opening adjustment',
        'entry_date'          => '2026-07-25',
    ]);
    Container::getInstance()->instance('request', $request);

    $response = (new JournalController())->createManual($request);
    expect($response->getStatusCode())->toBe(201)
        ->and($service->calls[0])->toMatchArray([
            'createJournalEntry',
            'debit-account',
            'credit-account',
            450,
            'Opening adjustment',
            [
                'company_uuid' => 'company-accounting-controller',
                'currency'     => 'MNT',
                'type'         => 'manual_entry',
                'entry_date'   => '2026-07-25',
            ],
        ]);

    accountingControllerAccount(['uuid' => 'foreign-debit', 'company_uuid' => 'another-company']);
    $foreign = AccountingControllerRequest::create('/journals', 'POST', [
        'debit_account_uuid'  => 'foreign-debit',
        'credit_account_uuid' => $credit->uuid,
        'amount'              => 1,
        'description'         => 'Invalid',
    ]);
    expect(fn () => (new JournalController())->createManual($foreign))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

test('account and journal resources expose complete persisted shapes', function () {
    $debit   = accountingControllerAccount(['uuid' => 'resource-debit', 'balance' => 1234]);
    $credit  = accountingControllerAccount(['uuid' => 'resource-credit']);
    $journal = accountingControllerJournal([
        'uuid'                => 'resource-journal',
        'debit_account_uuid'  => $debit->uuid,
        'credit_account_uuid' => $credit->uuid,
        'is_system_entry'     => true,
        'meta'                => ['source' => 'test'],
    ])->load(['debitAccount', 'creditAccount']);

    $accountPayload = (new AccountResource($debit))->toArray(new Request());
    $journalPayload = (new JournalResource($journal))->toArray(new Request());
    expect($accountPayload)->toMatchArray([
        'name'     => 'Cash',
        'balance'  => 1234,
        'currency' => 'USD',
        'status'   => 'active',
    ])
        ->and($journalPayload)->toMatchArray([
            'number'          => $journal->number,
            'is_system_entry' => true,
            'amount'          => 100,
            'meta'            => ['source' => 'test'],
        ])
        ->and($journalPayload['debit_account'])->toBeInstanceOf(AccountResource::class)
        ->and($journalPayload['credit_account'])->toBeInstanceOf(AccountResource::class);
});
