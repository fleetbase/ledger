<?php

use Fleetbase\Ledger\Http\Controllers\Internal\v1\ReportController;
use Fleetbase\Ledger\Models\Account;
use Fleetbase\Ledger\Models\Journal;
use Fleetbase\Ledger\Models\Wallet;
use Fleetbase\Ledger\Services\LedgerService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;

class ReportControllerRequest extends Request
{
    public function validate(array $rules, ...$params)
    {
        return $this->all();
    }
}

class ReportWalletDriver extends Model
{
    protected $table      = 'drivers';
    protected $primaryKey = 'uuid';
    public $incrementing  = false;
    protected $keyType    = 'string';
}

class ReportWalletCustomer extends Model
{
    protected $table      = 'customers';
    protected $primaryKey = 'uuid';
    public $incrementing  = false;
    protected $keyType    = 'string';
}

class ReportWalletCompany extends Model
{
    protected $table      = 'companies';
    protected $primaryKey = 'uuid';
    public $incrementing  = false;
    protected $keyType    = 'string';
}

class ReportControllerLedgerSpy extends LedgerService
{
    public array $calls    = [];
    public array $journals = [];

    private function result(string $method, array $arguments): array
    {
        $this->calls[] = [$method, ...$arguments];

        return ['report' => $method, 'arguments' => $arguments];
    }

    public function getDashboardMetrics(string $companyUuid, ?string $startDate = null, ?string $endDate = null): array
    {
        return $this->result(__FUNCTION__, func_get_args());
    }

    public function getDashboardSummary(string $companyUuid, ?string $startDate = null, ?string $endDate = null): array
    {
        return $this->result(__FUNCTION__, func_get_args());
    }

    public function getDashboardRevenueTrend(string $companyUuid, ?string $startDate = null, ?string $endDate = null): array
    {
        return $this->result(__FUNCTION__, func_get_args());
    }

    public function getDashboardCashFlowSummary(string $companyUuid, ?string $startDate = null, ?string $endDate = null): array
    {
        return $this->result(__FUNCTION__, func_get_args());
    }

    public function getDashboardInvoiceStatus(string $companyUuid): array
    {
        return $this->result(__FUNCTION__, func_get_args());
    }

    public function getDashboardArAgingSummary(string $companyUuid, ?string $asOfDate = null): array
    {
        return $this->result(__FUNCTION__, func_get_args());
    }

    public function getDashboardWalletBalances(string $companyUuid, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        return $this->result(__FUNCTION__, func_get_args());
    }

    public function getDashboardActivity(string $companyUuid, int $limit = 10): array
    {
        return $this->result(__FUNCTION__, func_get_args());
    }

    public function getTrialBalance(string $companyUuid, ?string $asOfDate = null): array
    {
        return $this->result(__FUNCTION__, func_get_args());
    }

    public function getBalanceSheet(string $companyUuid, ?string $asOfDate = null): array
    {
        return $this->result(__FUNCTION__, func_get_args());
    }

    public function getIncomeStatement(string $companyUuid, ?string $startDate = null, ?string $endDate = null): array
    {
        return $this->result(__FUNCTION__, func_get_args());
    }

    public function getCashFlowSummary(string $companyUuid, ?string $startDate = null, ?string $endDate = null): array
    {
        return $this->result(__FUNCTION__, func_get_args());
    }

    public function getArAging(string $companyUuid, ?string $asOfDate = null): array
    {
        return $this->result(__FUNCTION__, func_get_args());
    }

    public function getGeneralLedger(Account $account, ?string $startDate = null, ?string $endDate = null): Collection
    {
        $this->calls[] = [__FUNCTION__, $account->uuid, $startDate, $endDate];

        return collect($this->journals[$account->uuid] ?? []);
    }
}

function reportControllerRequest(array $input = []): ReportControllerRequest
{
    return ReportControllerRequest::create('/ledger/reports', 'GET', $input);
}

function bootReportControllerDatabase(): void
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
    $schema->create('ledger_wallets', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('_key')->nullable();
        $table->string('company_uuid');
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('name')->nullable();
        $table->bigInteger('balance')->default(0);
        $table->string('currency');
        $table->string('status');
        $table->text('meta')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('transactions', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid');
        $table->string('direction');
        $table->string('currency');
        $table->bigInteger('amount');
        $table->string('status');
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('ledger_accounts', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('_key')->nullable();
        $table->string('company_uuid');
        $table->string('name');
        $table->string('code');
        $table->string('type');
        $table->bigInteger('balance')->default(0);
        $table->string('currency')->nullable();
        $table->string('status');
        $table->text('meta')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    foreach (['customers', 'companies', 'drivers'] as $tableName) {
        $schema->create($tableName, function (Blueprint $table) {
            $table->string('uuid')->primary();
            $table->string('public_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }
}

function reportControllerAccount(string $uuid, string $type, string $code): Account
{
    return Account::withoutEvents(function () use ($uuid, $type, $code) {
        $account = new Account();
        $account->forceFill([
            'uuid'         => $uuid,
            'public_id'    => 'account_' . $uuid,
            'company_uuid' => 'company-report-controller',
            'name'         => ucfirst($uuid),
            'code'         => $code,
            'type'         => $type,
            'balance'      => 0,
            'currency'     => 'USD',
            'status'       => 'active',
        ]);
        $account->save();

        return $account;
    });
}

beforeEach(function () {
    session(['company' => 'company-report-controller']);
});

test('dashboard endpoints forward tenant date windows and activity limits', function () {
    $ledger     = new ReportControllerLedgerSpy();
    $controller = new ReportController($ledger);
    $period     = reportControllerRequest([
        'start_date' => '2026-01-01',
        'end_date'   => '2026-01-31',
        'as_of_date' => '2026-01-31',
        'date_from'  => '2026-01-02',
        'date_to'    => '2026-01-30',
        'limit'      => '7',
    ]);

    $responses = [
        $controller->dashboard($period),
        $controller->dashboardSummary($period),
        $controller->dashboardRevenueTrend($period),
        $controller->dashboardCashFlowSummary($period),
        $controller->dashboardInvoiceStatus(),
        $controller->dashboardArAgingSummary($period),
        $controller->dashboardWalletBalances($period),
        $controller->dashboardActivity($period),
    ];

    foreach ($responses as $response) {
        expect($response->getStatusCode())->toBe(200)
            ->and($response->getData(true)['status'])->toBe('ok');
    }
    expect($ledger->calls)->toBe([
        ['getDashboardMetrics', 'company-report-controller', '2026-01-01', '2026-01-31'],
        ['getDashboardSummary', 'company-report-controller', '2026-01-01', '2026-01-31'],
        ['getDashboardRevenueTrend', 'company-report-controller', '2026-01-01', '2026-01-31'],
        ['getDashboardCashFlowSummary', 'company-report-controller', '2026-01-01', '2026-01-31'],
        ['getDashboardInvoiceStatus', 'company-report-controller'],
        ['getDashboardArAgingSummary', 'company-report-controller', '2026-01-31'],
        ['getDashboardWalletBalances', 'company-report-controller', '2026-01-02', '2026-01-30'],
        ['getDashboardActivity', 'company-report-controller', 7],
    ]);
});

test('dashboard endpoints preserve optional null dates and default activity limit', function () {
    $ledger     = new ReportControllerLedgerSpy();
    $controller = new ReportController($ledger);
    $empty      = reportControllerRequest();

    $controller->dashboard($empty);
    $controller->dashboardSummary($empty);
    $controller->dashboardRevenueTrend($empty);
    $controller->dashboardCashFlowSummary($empty);
    $controller->dashboardArAgingSummary($empty);
    $controller->dashboardWalletBalances($empty);
    $controller->dashboardActivity($empty);

    expect($ledger->calls[0])->toBe(['getDashboardMetrics', 'company-report-controller', null, null])
        ->and($ledger->calls[4])->toBe(['getDashboardArAgingSummary', 'company-report-controller', null])
        ->and($ledger->calls[5])->toBe(['getDashboardWalletBalances', 'company-report-controller', null, null])
        ->and($ledger->calls[6])->toBe(['getDashboardActivity', 'company-report-controller', 10]);
});

test('financial statement endpoints delegate exact reporting periods', function () {
    $ledger     = new ReportControllerLedgerSpy();
    $controller = new ReportController($ledger);
    $request    = reportControllerRequest([
        'as_of_date' => '2026-03-31',
        'start_date' => '2026-01-01',
        'end_date'   => '2026-03-31',
    ]);

    $responses = [
        $controller->trialBalance($request),
        $controller->balanceSheet($request),
        $controller->incomeStatement($request),
        $controller->cashFlow($request),
        $controller->arAging($request),
    ];

    foreach ($responses as $response) {
        expect($response->getData(true)['data']['report'])->toBeString();
    }
    expect($ledger->calls)->toBe([
        ['getTrialBalance', 'company-report-controller', '2026-03-31'],
        ['getBalanceSheet', 'company-report-controller', '2026-03-31'],
        ['getIncomeStatement', 'company-report-controller', '2026-01-01', '2026-03-31'],
        ['getCashFlowSummary', 'company-report-controller', '2026-01-01', '2026-03-31'],
        ['getArAging', 'company-report-controller', '2026-03-31'],
    ]);
});

test('wallet summary groups owner types currencies activity and top-wallet subjects', function () {
    bootReportControllerDatabase();
    $now = now();
    foreach ([
        ['drivers', 'driver-one', 'Driver One', null],
        ['customers', 'customer-one', null, 'customer@example.test'],
        ['companies', 'company-owner', null, null],
    ] as [$table, $uuid, $name, $email]) {
        Capsule::table($table)->insert([
            'uuid'       => $uuid,
            'public_id'  => $uuid . '-public',
            'name'       => $name,
            'email'      => $email,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
    foreach ([
        ['wallet-driver', ReportWalletDriver::class, 'driver-one', 'Driver earnings', 5000, 'USD'],
        ['wallet-customer', ReportWalletCustomer::class, 'customer-one', 'Customer credit', 2500, 'USD'],
        ['wallet-company', ReportWalletCompany::class, 'company-owner', 'Company wallet', 3000, 'MNT'],
        ['wallet-other', 'ReportWalletOther', null, 'Other wallet', 100, 'EUR'],
    ] as [$uuid, $subjectType, $subjectUuid, $name, $balance, $currency]) {
        Capsule::table('ledger_wallets')->insert([
            'uuid'         => $uuid,
            'public_id'    => $uuid . '-public',
            'company_uuid' => 'company-report-controller',
            'subject_uuid' => $subjectUuid,
            'subject_type' => $subjectType,
            'name'         => $name,
            'balance'      => $balance,
            'currency'     => $currency,
            'status'       => $uuid === 'wallet-other' ? Wallet::STATUS_CLOSED : Wallet::STATUS_ACTIVE,
            'meta'         => json_encode([]),
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }
    foreach ([
        ['transaction-credit-usd', 'credit', 'USD', 1000],
        ['transaction-debit-usd', 'debit', 'USD', 350],
        ['transaction-credit-mnt', 'credit', 'MNT', 8000],
    ] as [$uuid, $direction, $currency, $amount]) {
        Capsule::table('transactions')->insert([
            'uuid'         => $uuid,
            'public_id'    => $uuid . '-public',
            'company_uuid' => 'company-report-controller',
            'direction'    => $direction,
            'currency'     => $currency,
            'amount'       => $amount,
            'status'       => 'completed',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }
    $controller = new ReportController(new ReportControllerLedgerSpy());

    $data = $controller->walletSummary(reportControllerRequest([
        'date_from' => $now->toDateString(),
        'date_to'   => $now->toDateString(),
    ]))->getData(true)['data'];

    expect($data['period'])->toBe(['from' => $now->toDateString(), 'to' => $now->toDateString()])
        ->and(collect($data['wallet_counts'])->pluck('type')->sort()->values()->all())
        ->toBe(['company', 'customer', 'driver', 'reportwalletother'])
        ->and($data['period_stats']['USD'])->toBe([
            'credits'      => 1000,
            'debits'       => 350,
            'credit_count' => 1,
            'debit_count'  => 1,
        ])->and($data['period_stats']['MNT']['credits'])->toBe(8000)
        ->and($data['top_wallets'][0])->toMatchArray([
            'wallet_public_id'  => 'wallet-driver-public',
            'name'              => 'Driver earnings',
            'type'              => 'driver',
            'balance'           => 5000,
            'formatted_balance' => '50.00',
            'subject'           => ['name' => 'Driver One'],
        ]);
});

test('wallet summary supplies default date window and handles empty collections', function () {
    bootReportControllerDatabase();
    $data = (new ReportController(new ReportControllerLedgerSpy()))
        ->walletSummary(reportControllerRequest())
        ->getData(true)['data'];

    expect($data['period']['from'])->toBe(now()->startOfMonth()->toDateString())
        ->and($data['period']['to'])->toBe(now()->toDateString())
        ->and($data['wallet_counts'])->toBe([])
        ->and($data['period_stats'])->toBe([])
        ->and($data['top_wallets'])->toBe([]);
});

test('general ledger shapes debit and credit normal running balances and summaries', function () {
    bootReportControllerDatabase();
    $asset     = reportControllerAccount('cash-account', Account::TYPE_ASSET, '1000');
    $liability = reportControllerAccount('payable-account', Account::TYPE_LIABILITY, '2000');
    reportControllerAccount('inactive-account', Account::TYPE_ASSET, '9999')->update(['status' => 'inactive']);
    $ledger           = new ReportControllerLedgerSpy();
    $ledger->journals = [
        $asset->uuid => [
            (new Journal())->forceFill([
                'public_id'           => 'journal-asset-debit',
                'debit_account_uuid'  => $asset->uuid,
                'credit_account_uuid' => $liability->uuid,
                'amount'              => 1000,
                'entry_date'          => '2026-01-02',
                'number'              => 'JE-1',
                'type'                => 'sale',
                'description'         => 'Cash received',
                'reference'           => 'SALE-1',
                'currency'            => 'USD',
                'is_system_entry'     => true,
            ]),
            (new Journal())->forceFill([
                'public_id'           => 'journal-asset-credit',
                'debit_account_uuid'  => $liability->uuid,
                'credit_account_uuid' => $asset->uuid,
                'amount'              => 250,
                'entry_date'          => null,
                'number'              => 'JE-2',
                'type'                => 'payment',
                'description'         => null,
                'memo'                => 'Cash paid',
                'reference'           => null,
                'currency'            => null,
                'is_system_entry'     => false,
            ]),
        ],
        $liability->uuid => [
            (new Journal())->forceFill([
                'public_id'           => 'journal-liability-credit',
                'debit_account_uuid'  => $asset->uuid,
                'credit_account_uuid' => $liability->uuid,
                'amount'              => 1000,
                'entry_date'          => '2026-01-02',
                'number'              => 'JE-1',
                'type'                => 'sale',
                'description'         => 'Payable created',
                'currency'            => 'USD',
                'is_system_entry'     => true,
            ]),
            (new Journal())->forceFill([
                'public_id'           => 'journal-liability-debit',
                'debit_account_uuid'  => $liability->uuid,
                'credit_account_uuid' => $asset->uuid,
                'amount'              => 250,
                'entry_date'          => '2026-01-03',
                'number'              => 'JE-2',
                'type'                => 'payment',
                'description'         => 'Payable settled',
                'currency'            => 'USD',
                'is_system_entry'     => false,
            ]),
        ],
    ];
    $controller = new ReportController($ledger);

    $data = $controller->generalLedger(reportControllerRequest([
        'date_from' => '2026-01-01',
        'date_to'   => '2026-01-31',
    ]))->getData(true)['data'];

    expect($data['accounts'])->toHaveCount(2)
        ->and($data['accounts'][0]['account']['code'])->toBe('1000')
        ->and($data['accounts'][0]['entries'][0]['running_balance'])->toBe(1000)
        ->and($data['accounts'][0]['entries'][1]['running_balance'])->toBe(750)
        ->and($data['accounts'][0]['entries'][1]['description'])->toBe('Cash paid')
        ->and($data['accounts'][0]['entries'][1]['currency'])->toBe('USD')
        ->and($data['accounts'][0]['summary'])->toMatchArray([
            'total_debits'  => 1000,
            'total_credits' => 250,
            'net_balance'   => 750,
            'entry_count'   => 2,
        ])->and($data['accounts'][1]['summary']['net_balance'])->toBe(750)
        ->and($ledger->calls)->toBe([
            ['getGeneralLedger', 'cash-account', '2026-01-01', '2026-01-31'],
            ['getGeneralLedger', 'payable-account', '2026-01-01', '2026-01-31'],
        ]);
});

test('general ledger applies account type filters', function () {
    bootReportControllerDatabase();
    reportControllerAccount('asset-filter', Account::TYPE_ASSET, '1000');
    reportControllerAccount('expense-filter', Account::TYPE_EXPENSE, '5000');
    $ledger = new ReportControllerLedgerSpy();

    $data = (new ReportController($ledger))->generalLedger(reportControllerRequest([
        'type' => 'expense',
    ]))->getData(true)['data'];

    expect($data['accounts'])->toHaveCount(1)
        ->and($data['accounts'][0]['account']['type'])->toBe('expense')
        ->and($ledger->calls)->toBe([['getGeneralLedger', 'expense-filter', null, null]]);
});
