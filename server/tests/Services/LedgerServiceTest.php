<?php

use Fleetbase\Ledger\Models\Account;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Models\Journal;
use Fleetbase\Ledger\Services\LedgerService;
use Fleetbase\TestSupport\LoggerManager;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;

class LedgerServiceProbe extends LedgerService
{
    public function fingerprint(Journal $journal): ?string
    {
        return $this->profitAndLossJournalFingerprint($journal);
    }

    public function deduplicate(Collection $journals): array
    {
        return $this->deduplicateProfitAndLossJournals($journals);
    }

    public function accountRow(Account $account): array
    {
        return $this->makeProfitAndLossAccountRow($account);
    }

    public function journalCurrency(Collection $journals): ?string
    {
        return $this->resolveCurrencyFromJournals($journals);
    }

    public function netFlow(array $items): int
    {
        return $this->computeNetFlow($items);
    }

    public function metric(string $label, array $source, string $format, string $currency, bool $inverse = false): array
    {
        return $this->makeDashboardMetric($label, $source, $format, $currency, $inverse);
    }

    public function dashboardCurrency(iterable $walletTotals = []): string
    {
        return $this->resolveDashboardCurrency($walletTotals);
    }

    public function change(int|float $previous, int|float $current): ?float
    {
        return $this->percentageChange($previous, $current);
    }
}

function bootLedgerServiceDatabase(): void
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
    $schema->create('ledger_accounts', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('_key')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('created_by_uuid')->nullable();
        $table->string('updated_by_uuid')->nullable();
        $table->string('name');
        $table->string('code');
        $table->string('type');
        $table->text('description')->nullable();
        $table->boolean('is_system_account')->default(false);
        $table->bigInteger('balance')->default(0);
        $table->string('currency')->nullable();
        $table->string('status')->default('active');
        $table->text('meta')->nullable();
        $table->softDeletes();
        $table->timestamps();
        $table->unique(['company_uuid', 'code']);
    });
    $schema->create('ledger_journals', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('_key')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('transaction_uuid')->nullable();
        $table->string('debit_account_uuid');
        $table->string('credit_account_uuid');
        $table->string('number')->nullable();
        $table->string('type')->nullable();
        $table->string('status')->nullable();
        $table->string('reference')->nullable();
        $table->text('memo')->nullable();
        $table->boolean('is_system_entry')->default(true);
        $table->bigInteger('amount');
        $table->string('currency')->nullable();
        $table->text('description')->nullable();
        $table->date('entry_date')->nullable();
        $table->text('meta')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('transactions', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('company_uuid')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('owner_type')->nullable();
        $table->string('type');
        $table->string('direction');
        $table->string('status');
        $table->bigInteger('amount');
        $table->string('currency')->nullable();
        $table->text('meta')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('ledger_invoices', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('company_uuid')->nullable();
        $table->string('customer_uuid')->nullable();
        $table->string('customer_type')->nullable();
        $table->string('number')->nullable();
        $table->date('date')->nullable();
        $table->date('due_date')->nullable();
        $table->bigInteger('subtotal')->default(0);
        $table->bigInteger('tax')->default(0);
        $table->bigInteger('total_amount')->default(0);
        $table->bigInteger('amount_paid')->default(0);
        $table->bigInteger('balance')->default(0);
        $table->string('currency')->nullable();
        $table->string('status');
        $table->text('meta')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('ledger_wallets', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('_key')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('created_by_uuid')->nullable();
        $table->string('updated_by_uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('name');
        $table->text('description')->nullable();
        $table->bigInteger('balance')->default(0);
        $table->string('currency');
        $table->string('status');
        $table->text('meta')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('ledger_invoice_items', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('invoice_uuid')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
}

function ledgerAccount(
    string $code,
    string $type,
    string $company = 'company-ledger',
    string $currency = 'USD',
    string $status = 'active',
): Account {
    return Account::create([
        'uuid'         => 'account-' . strtolower($code) . '-' . $company,
        'public_id'    => 'account_public_' . strtolower($code) . '_' . $company,
        'company_uuid' => $company,
        'name'         => $code . ' Account',
        'code'         => $code,
        'type'         => $type,
        'currency'     => $currency,
        'status'       => $status,
    ]);
}

function ledgerJournal(
    Account $debit,
    Account $credit,
    int $amount,
    string $date,
    array $attributes = [],
): Journal {
    static $sequence = 0;
    $sequence++;

    $journal = Journal::create(array_merge([
        'uuid'                => sprintf('40000000-0000-4000-8000-%012d', $sequence),
        'public_id'           => 'journal_public_' . $sequence,
        'company_uuid'        => $debit->company_uuid,
        'debit_account_uuid'  => $debit->uuid,
        'credit_account_uuid' => $credit->uuid,
        'amount'              => $amount,
        'currency'            => 'USD',
        'description'         => 'Journal ' . $sequence,
        'type'                => 'general',
        'status'              => 'posted',
        'entry_date'          => $date,
        'meta'                => [],
    ], $attributes));
    Capsule::table('ledger_journals')->where('uuid', $journal->uuid)->update(['entry_date' => $date]);

    return $journal->refresh();
}

function insertLedgerInvoice(
    string $uuid,
    string $status,
    int $balance,
    string $dueDate,
    string $currency = 'USD',
    string $company = 'company-ledger',
): void {
    Capsule::table('ledger_invoices')->insert([
        'uuid'         => $uuid,
        'public_id'    => $uuid . '-public',
        'company_uuid' => $company,
        'number'       => strtoupper($uuid),
        'date'         => '2026-01-01',
        'due_date'     => $dueDate,
        'total_amount' => $balance + 100,
        'amount_paid'  => 100,
        'balance'      => $balance,
        'currency'     => $currency,
        'status'       => $status,
        'meta'         => '{}',
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
}

beforeEach(function () {
    bootLedgerServiceDatabase();
    session(['company' => 'company-ledger']);
    LoggerManager::$records = [];
});

test('journal creation and convenience methods preserve double-entry contracts', function () {
    $cash    = ledgerAccount('1000', Account::TYPE_ASSET);
    $revenue = ledgerAccount('4000', Account::TYPE_REVENUE);
    $expense = ledgerAccount('5000', Account::TYPE_EXPENSE);
    $service = new LedgerService();

    $journal = $service->createJournalEntry($cash, $revenue, 1200, 'Invoice paid', [
        'transaction_uuid'         => 'transaction-source',
        'reference'                => 'INV-100',
        'memo'                     => 'Settlement',
        'journal_type'             => 'invoice_payment',
        'is_system_entry'          => false,
        'entry_date'               => '2026-01-10',
        'subject_uuid'             => 'invoice-100',
        'subject_type'             => Invoice::class,
        'gateway_transaction_uuid' => 'gateway-100',
        'meta'                     => ['subject_uuid' => 'preserved-subject'],
    ]);
    $expenseJournal = $service->recordExpense($expense, $cash, 200, 'Fuel', ['entry_date' => '2026-01-11']);
    $revenueJournal = $service->recordRevenue($cash, $revenue, 300, 'Extra revenue', ['entry_date' => '2026-01-12']);
    $transfer       = $service->transfer($cash, $expense, 50, 'Internal transfer', ['entry_date' => '2026-01-13']);

    expect($journal->number)->toBe('JE-00001')
        ->and($journal->type)->toBe('invoice_payment')
        ->and($journal->reference)->toBe('INV-100')
        ->and($journal->memo)->toBe('Settlement')
        ->and($journal->is_system_entry)->toBeFalse()
        ->and($journal->meta['subject_uuid'])->toBe('preserved-subject')
        ->and($journal->meta['subject_type'])->toBe(Invoice::class)
        ->and($journal->meta['gateway_transaction_uuid'])->toBe('gateway-100')
        ->and($expenseJournal->type)->toBe('expense')
        ->and($revenueJournal->type)->toBe('revenue')
        ->and($transfer->type)->toBe('transfer')
        ->and($cash->fresh()->balance)->toBe(1350)
        ->and($revenue->fresh()->balance)->toBe(1500)
        ->and($expense->fresh()->balance)->toBe(150);
});

test('general ledger balances trial balance and balance sheet honor dates and account normals', function () {
    $asset     = ledgerAccount('1000', Account::TYPE_ASSET);
    $liability = ledgerAccount('2000', Account::TYPE_LIABILITY);
    $equity    = ledgerAccount('3000', Account::TYPE_EQUITY);
    $revenue   = ledgerAccount('4000', Account::TYPE_REVENUE);
    $expense   = ledgerAccount('5000', Account::TYPE_EXPENSE);
    ledgerAccount('9999', Account::TYPE_ASSET, status: 'inactive');

    ledgerJournal($asset, $liability, 1000, '2026-01-01');
    ledgerJournal($expense, $asset, 200, '2026-01-02');
    ledgerJournal($asset, $equity, 300, '2026-02-01');
    ledgerJournal($asset, $revenue, 0, '2026-01-03');
    $service = new LedgerService();

    $general       = $service->getGeneralLedger($asset, '2026-01-01', '2026-01-31');
    $trial         = $service->getTrialBalance('company-ledger', '2026-01-31');
    $sheet         = $service->getBalanceSheet('company-ledger', '2026-01-31');
    $februarySheet = $service->getBalanceSheet('company-ledger', '2026-02-28');

    expect($general)->toHaveCount(3)
        ->and($service->getBalanceAtDate($asset, '2026-01-31'))->toBe(800)
        ->and($service->getBalanceAtDate($liability, '2026-01-31'))->toBe(1000)
        ->and($service->getBalanceAtDate($expense, '2026-01-31'))->toBe(200)
        ->and($trial['debit_total'])->toBe(1000)
        ->and($trial['credit_total'])->toBe(1000)
        ->and($trial['balanced'])->toBeTrue()
        ->and($trial['as_of_date'])->toBe('2026-01-31')
        ->and($sheet['total_assets'])->toBe(800)
        ->and($sheet['total_liabilities'])->toBe(1000)
        ->and($sheet['total_equity'])->toBe(0)
        ->and($sheet['balanced'])->toBeFalse()
        ->and($sheet['assets'][0]['code'])->toBe('1000')
        ->and($sheet['liabilities'][0]['code'])->toBe('2000')
        ->and($sheet['equity'])->toBe([])
        ->and($februarySheet['equity'][0]['code'])->toBe('3000')
        ->and($februarySheet['total_equity'])->toBe(300);
});

test('profit and loss reports normalize reversals, expenses, currencies, and source duplicates', function () {
    $cash    = ledgerAccount('1000', Account::TYPE_ASSET);
    $revenue = ledgerAccount('4000', Account::TYPE_REVENUE);
    $expense = ledgerAccount('5000', Account::TYPE_EXPENSE);

    ledgerJournal($cash, $revenue, 1000, '2026-01-01', ['type' => 'invoice_revenue', 'meta' => ['invoice_uuid' => 'invoice-a']]);
    ledgerJournal($cash, $revenue, 1000, '2026-01-01', ['type' => 'invoice_revenue', 'meta' => ['invoice_uuid' => 'invoice-a']]);
    ledgerJournal($revenue, $cash, 100, '2026-01-02', ['type' => 'invoice_reversal', 'meta' => ['reverses_journal_uuid' => 'original-a']]);
    ledgerJournal($expense, $cash, 300, '2026-01-02', ['type' => 'expense', 'meta' => ['order_uuid' => 'order-a']]);
    ledgerJournal($cash, $expense, 50, '2026-01-03', ['type' => 'expense_reversal', 'meta' => ['reverses_journal_uuid' => 'expense-a']]);

    $service = new LedgerService();
    $income  = $service->getIncomeStatement('company-ledger', '2026-01-01', '2026-01-03');
    $trend   = $service->getDashboardRevenueTrend('company-ledger', '2026-01-01', '2026-01-03');

    expect($income['total_revenue'])->toBe(900)
        ->and($income['total_expenses'])->toBe(250)
        ->and($income['net_income'])->toBe(650)
        ->and($income['profitable'])->toBeTrue()
        ->and($income['currency'])->toBe('USD')
        ->and($income['audit']['journal_rows'])->toBe(5)
        ->and($income['audit']['counted_rows'])->toBe(4)
        ->and($income['audit']['deduplicated_rows'])->toBe(1)
        ->and($income['daily'])->toHaveCount(3)
        ->and($trend['summary'])->toBe(['revenue' => 900, 'expenses' => 250, 'net' => 650])
        ->and($trend['datasets'][0]['data']->all())->toBe([1000, -100, 0])
        ->and($trend['datasets'][1]['data']->all())->toBe([0, 300, -50]);
});

test('profit and loss fingerprint helpers distinguish every authoritative source', function () {
    $probe   = new LedgerServiceProbe();
    $account = ledgerAccount('4000', Account::TYPE_REVENUE);
    $cash    = ledgerAccount('1000', Account::TYPE_ASSET);

    $cases = [
        ['type' => 'sale_reversal', 'meta' => ['reverses_journal_uuid' => 'j1'], 'prefix' => 'journal-reversal|'],
        ['type' => 'sale_reversal', 'meta' => [], 'prefix' => 'journal-reversal|'],
        ['type' => 'sale_reinstatement', 'meta' => ['reinstates_journal_uuid' => 'j2'], 'prefix' => 'journal-reinstatement|'],
        ['type' => 'sale_reinstatement', 'meta' => [], 'prefix' => 'journal-reinstatement|'],
        ['type' => 'revenue', 'description' => 'Revenue recognition for invoice INV-55 [sale]', 'prefix' => 'invoice-number|'],
        ['type' => 'revenue', 'meta' => ['invoice_uuid' => 'invoice-55'], 'prefix' => 'invoice|'],
        ['type' => 'revenue', 'meta' => ['order_uuid' => 'order-55'], 'prefix' => 'order|'],
        ['type' => 'gateway_payment', 'meta' => ['gateway_transaction_uuid' => 'gateway-55'], 'prefix' => 'gateway-transaction|'],
        ['type' => 'expense', 'transaction_uuid' => 'transaction-55', 'prefix' => 'transaction|'],
    ];

    foreach ($cases as $case) {
        $journal = new Journal(array_merge([
            'uuid'             => uniqid('journal-', true),
            'company_uuid'     => 'company-ledger',
            'amount'           => 100,
            'currency'         => 'USD',
            'description'      => 'Unmatched',
            'meta'             => [],
            'transaction_uuid' => null,
        ], $case));
        expect($probe->fingerprint($journal))->toStartWith($case['prefix']);
    }

    $unkeyed               = new Journal(['company_uuid' => 'company-ledger', 'type' => 'general']);
    [$unique, $duplicates] = $probe->deduplicate(collect([$unkeyed, $unkeyed]));

    expect($probe->fingerprint($unkeyed))->toBeNull()
        ->and($unique)->toHaveCount(2)
        ->and($duplicates)->toBe(0)
        ->and($probe->accountRow($account))->toBe([
            'uuid' => $account->uuid, 'code' => '4000', 'name' => '4000 Account', 'balance' => 0,
        ])
        ->and($probe->journalCurrency(collect([
            new Journal(['currency' => 'USD']),
            new Journal(['currency' => 'USD']),
        ])))->toBe('USD')
        ->and($probe->journalCurrency(collect([
            new Journal(['currency' => 'USD']),
            new Journal(['currency' => 'EUR']),
        ])))->toBeNull();
});

test('cash flow categorizes transaction types and cross-validates the cash ledger', function () {
    $cash      = ledgerAccount('1000', Account::TYPE_ASSET);
    $offset    = ledgerAccount('3000', Account::TYPE_EQUITY);
    $service   = new LedgerService();
    ledgerJournal($cash, $offset, 500, '2025-12-31');
    ledgerJournal($cash, $offset, 200, '2026-01-15');
    Capsule::table('ledger_journals')->where('number', 'JE-00001')->update(['entry_date' => '2025-12-31']);
    Capsule::table('ledger_journals')->where('number', 'JE-00002')->update(['entry_date' => '2026-01-15']);

    $rows = [
        ['earning', 'credit', 500],
        ['fee', 'debit', 50],
        ['deposit', 'credit', 1000],
        ['withdrawal', 'debit', 200],
        ['asset_purchase', 'debit', 300],
    ];
    foreach ($rows as $index => [$type, $direction, $amount]) {
        Capsule::table('transactions')->insert([
            'uuid'         => 'cash-flow-' . $index,
            'public_id'    => 'cash_flow_public_' . $index,
            'company_uuid' => 'company-ledger',
            'type'         => $type,
            'direction'    => $direction,
            'status'       => 'completed',
            'amount'       => $amount,
            'currency'     => 'USD',
            'created_at'   => '2026-01-10 00:00:00',
            'updated_at'   => '2026-01-10 00:00:00',
        ]);
    }

    $flow      = $service->getCashFlowSummary('company-ledger', '2026-01-01', '2026-01-31');
    $dashboard = $service->getDashboardCashFlowSummary('company-ledger', '2026-01-01', '2026-01-31');

    expect($flow['operating_activities']['net_flow'])->toBe(450)
        ->and($flow['financing_activities']['net_flow'])->toBe(800)
        ->and($flow['investing_activities']['net_flow'])->toBe(-300)
        ->and($flow['net_cash_change'])->toBe(950)
        ->and($flow['cash_account'])->toBe([
            'opening_balance' => 500,
            'closing_balance' => 700,
            'net_change'      => 200,
        ])
        ->and($dashboard['operating'])->toBe(450)
        ->and($dashboard['currency'])->toBe('USD')
        ->and((new LedgerServiceProbe())->netFlow([
            ['direction' => 'credit', 'total' => 10],
            ['direction' => 'debit', 'total' => 4],
        ]))->toBe(6);
});

test('invoice status and aging reports retain every bucket and currency contract', function () {
    insertLedgerInvoice('invoice-current', 'sent', 100, '2026-02-01');
    insertLedgerInvoice('invoice-15', 'overdue', 200, '2026-01-16');
    insertLedgerInvoice('invoice-45', 'overdue', 300, '2025-12-17');
    insertLedgerInvoice('invoice-75', 'overdue', 400, '2025-11-17');
    insertLedgerInvoice('invoice-100', 'overdue', 500, '2025-10-23');
    insertLedgerInvoice('invoice-paid', 'paid', 0, '2025-10-01');
    insertLedgerInvoice('invoice-eur', 'sent', 50, '2026-02-01', 'EUR');

    $service = new LedgerService();
    $aging   = $service->getArAging('company-ledger', '2026-01-31');
    $compact = $service->getDashboardArAgingSummary('company-ledger', '2026-01-31');
    $status  = $service->getDashboardInvoiceStatus('company-ledger');

    expect($aging['total_invoices'])->toBe(6)
        ->and($aging['grand_total'])->toBe(1550)
        ->and($aging['buckets']['current']['total'])->toBe(150)
        ->and($aging['buckets']['1_30']['total'])->toBe(200)
        ->and($aging['buckets']['31_60']['total'])->toBe(300)
        ->and($aging['buckets']['61_90']['total'])->toBe(400)
        ->and($aging['buckets']['over_90']['total'])->toBe(500)
        ->and($compact['buckets'])->toHaveCount(5)
        ->and($status['total_count'])->toBe(7)
        ->and($status['total_open'])->toBe(1550)
        ->and($status['summary']->firstWhere('status', 'sent')['currency'])->toBeNull();
});

test('dashboard aggregates metrics wallets activity and percentage changes', function () {
    $cash    = ledgerAccount('1000', Account::TYPE_ASSET);
    $revenue = ledgerAccount('4000', Account::TYPE_REVENUE);
    $expense = ledgerAccount('5000', Account::TYPE_EXPENSE);
    ledgerJournal($cash, $revenue, 1000, '2026-01-10', ['type' => 'revenue']);
    ledgerJournal($expense, $cash, 250, '2026-01-11', ['type' => 'expense']);
    ledgerJournal($cash, $revenue, 400, '2025-12-20', ['type' => 'revenue']);
    insertLedgerInvoice('invoice-open', 'sent', 600, '2026-01-15');

    Capsule::table('ledger_wallets')->insert([
        ['uuid' => 'wallet-usd', 'public_id' => 'wallet_usd', 'company_uuid' => 'company-ledger', 'name' => 'USD Wallet', 'balance' => 1200, 'currency' => 'USD', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ['uuid' => 'wallet-eur', 'public_id' => 'wallet_eur', 'company_uuid' => 'company-ledger', 'name' => 'EUR Wallet', 'balance' => 800, 'currency' => 'EUR', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ['uuid' => 'wallet-closed', 'public_id' => 'wallet_closed', 'company_uuid' => 'company-ledger', 'name' => 'Closed', 'balance' => 999, 'currency' => 'USD', 'status' => 'closed', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $service           = new LedgerService();
    $metrics           = $service->getDashboardMetrics('company-ledger', '2026-01-01', '2026-01-31');
    $summary           = $service->getDashboardSummary('company-ledger', '2026-01-01', '2026-01-31');
    $wallets           = $service->getDashboardWalletBalances('company-ledger', '2026-01-01', '2026-01-31');
    $activity          = $service->getDashboardActivity('company-ledger', 2);
    $emptyTrendMetrics = $service->getDashboardMetrics('company-ledger-empty', '2026-01-02', '2026-01-01');
    $probe             = new LedgerServiceProbe();

    expect($metrics['kpis']['total_revenue']['current'])->toBe(1000)
        ->and($metrics['kpis']['total_revenue']['previous'])->toBe(400)
        ->and($metrics['kpis']['net_income']['profitable'])->toBeTrue()
        ->and($metrics['kpis']['outstanding_ar'])->toBe(['total' => 600, 'overdue' => 600])
        ->and($summary['metrics']['wallet_balance']['multi_currency'])->toBeTrue()
        ->and($summary['metrics']['wallet_balance']['value'])->toBeNull()
        ->and($summary['metrics']['active_wallets']['value'])->toBe(2)
        ->and($wallets['totals'])->toHaveCount(2)
        ->and($wallets['top_wallets'])->toHaveCount(2)
        ->and($wallets['top_wallets'][0]['formatted_balance'])->toBe('12.00')
        ->and($activity['items'])->toHaveCount(2)
        ->and($activity['items'][0]['debit'])->not->toBeNull()
        ->and($emptyTrendMetrics['revenue_trend'])->toBeEmpty()
        ->and($probe->change(0, 10))->toBe(100.0)
        ->and($probe->change(0, 0))->toBeNull()
        ->and($probe->change(-20, 10))->toBe(150.0)
        ->and($probe->dashboardCurrency([['currency' => 'MNT']]))->toBe('MNT')
        ->and($probe->dashboardCurrency([['currency' => ''], ['currency' => null]]))->toBe('USD')
        ->and($probe->metric('Revenue', [], 'money', 'USD'))->toMatchArray([
            'value' => 0, 'previous' => null, 'delta_percent' => null, 'currency' => 'USD', 'inverse' => false,
        ]);
});
