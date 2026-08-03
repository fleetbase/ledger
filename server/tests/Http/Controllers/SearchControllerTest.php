<?php

use Fleetbase\Ledger\Http\Controllers\Internal\v1\SearchController;
use Fleetbase\Models\User;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

class SearchControllerUser extends User
{
    public function isAdmin(): bool
    {
        return true;
    }
}

class SearchControllerAuthGuard
{
    public static bool $admin = true;

    public function user(): ?User
    {
        return self::$admin ? new SearchControllerUser() : null;
    }
}

if (!function_exists('auth')) {
    function auth(): SearchControllerAuthGuard
    {
        return new SearchControllerAuthGuard();
    }
}

function bootSearchControllerDatabase(): Capsule
{
    $capsule    = new Capsule(Container::getInstance());
    $dispatcher = new Dispatcher(Container::getInstance());
    $database   = tempnam(sys_get_temp_dir(), 'ledger-search-controller-');
    $capsule->setEventDispatcher($dispatcher);
    Model::setEventDispatcher($dispatcher);
    Model::clearBootedModels();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => $database, 'prefix' => ''], 'testing');
    $capsule->addConnection(['driver' => 'sqlite', 'database' => $database, 'prefix' => ''], 'mysql');
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('testing');
    Container::getInstance()->instance('db', $capsule->getDatabaseManager());
    Container::getInstance()->make('config')->set('auth.defaults.guard', 'web');
    Container::getInstance()->make('config')->set('auth.guards.web', ['driver' => 'session', 'provider' => 'users']);
    Container::getInstance()->make('config')->set('auth.providers.users', ['driver' => 'eloquent', 'model' => User::class]);
    Facade::clearResolvedInstance('db');
    session(['company' => 'company-search-controller']);

    $schema = $capsule->getConnection('testing')->getSchemaBuilder();
    $schema->create('ledger_invoices', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid');
        $table->string('number')->nullable();
        $table->string('status')->nullable();
        $table->string('currency')->nullable();
        $table->string('customer_uuid')->nullable();
        $table->string('customer_type')->nullable();
        $table->bigInteger('total_amount')->default(0);
        $table->bigInteger('balance')->default(0);
        $table->softDeletes();
    });
    $schema->create('ledger_invoice_items', function (Blueprint $table) {
        $table->increments('id');
        $table->string('invoice_uuid');
        $table->softDeletes();
    });
    $schema->create('templates', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid');
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->string('context_type')->nullable();
        $table->softDeletes();
    });
    $schema->create('ledger_wallets', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid');
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->string('currency')->nullable();
        $table->string('status')->nullable();
        $table->bigInteger('balance')->default(0);
        $table->softDeletes();
    });
    $schema->create('transactions', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid');
        $table->bigInteger('amount')->default(0);
        $table->string('currency')->nullable();
        $table->string('status')->nullable();
        $table->string('type')->nullable();
        $table->text('description')->nullable();
        $table->string('reference')->nullable();
        $table->softDeletes();
    });
    $schema->create('ledger_gateways', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid');
        $table->string('name')->nullable();
        $table->string('driver')->nullable();
        $table->text('description')->nullable();
        $table->string('status')->nullable();
        $table->string('environment')->nullable();
        $table->softDeletes();
    });
    $schema->create('ledger_accounts', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid');
        $table->string('name')->nullable();
        $table->string('code')->nullable();
        $table->string('type')->nullable();
        $table->text('description')->nullable();
        $table->string('currency')->nullable();
        $table->string('status')->nullable();
        $table->softDeletes();
    });
    $schema->create('ledger_journals', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid');
        $table->string('number')->nullable();
        $table->string('reference')->nullable();
        $table->text('memo')->nullable();
        $table->string('type')->nullable();
        $table->string('status')->nullable();
        $table->text('description')->nullable();
        $table->string('currency')->nullable();
        $table->softDeletes();
    });
    $schema->create('permissions', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('name');
        $table->string('guard_name')->default('web');
    });

    return $capsule;
}

function seedSearchControllerRecords(): void
{
    $company = 'company-search-controller';
    Capsule::table('ledger_invoices')->insert([
        'uuid'         => 'invoice-uuid', 'public_id' => 'invoice_public', 'company_uuid' => $company,
        'number'       => 'INV-NEEDLE', 'status' => 'sent', 'currency' => 'USD',
        'total_amount' => 12500, 'balance' => 12500,
    ]);
    Capsule::table('templates')->insert([
        'uuid' => 'template-uuid', 'public_id' => 'template_public', 'company_uuid' => $company,
        'name' => 'Needle Template', 'description' => null, 'context_type' => 'ledger-invoice',
    ]);
    Capsule::table('ledger_wallets')->insert([
        'uuid' => 'wallet-uuid', 'public_id' => 'wallet_public', 'company_uuid' => $company,
        'name' => null, 'description' => 'needle reserve', 'currency' => 'USD', 'status' => 'active',
    ]);
    Capsule::table('transactions')->insert([
        'uuid'        => 'transaction-uuid', 'public_id' => null, 'company_uuid' => $company,
        'amount'      => 12500, 'currency' => 'USD', 'status' => 'completed', 'type' => 'credit',
        'description' => null, 'reference' => 'NEEDLE-TXN',
    ]);
    Capsule::table('ledger_gateways')->insert([
        'uuid' => 'gateway-uuid', 'public_id' => 'gateway_public', 'company_uuid' => $company,
        'name' => null, 'driver' => 'needle-driver', 'description' => null, 'status' => 'active', 'environment' => 'sandbox',
    ]);
    Capsule::table('ledger_accounts')->insert([
        'uuid'     => 'account-uuid', 'public_id' => 'account_public', 'company_uuid' => $company,
        'name'     => null, 'code' => 'NEEDLE-1000', 'type' => 'asset', 'description' => null,
        'currency' => 'USD', 'status' => 'active',
    ]);
    Capsule::table('ledger_journals')->insert([
        'uuid'   => 'journal-uuid', 'public_id' => 'journal_public', 'company_uuid' => $company,
        'number' => null, 'reference' => 'NEEDLE-JRN', 'memo' => 'needle memo', 'type' => 'general',
        'status' => 'posted', 'description' => null, 'currency' => 'USD',
    ]);

    Capsule::table('ledger_invoices')->insert([
        'uuid'         => 'foreign-invoice', 'public_id' => 'foreign', 'company_uuid' => 'another-company',
        'number'       => 'INV-NEEDLE-FOREIGN', 'status' => 'sent', 'currency' => 'USD',
        'total_amount' => 1, 'balance' => 1,
    ]);
}

function searchControllerResults(array $input): array
{
    $response = (new SearchController())->search(Request::create('/ledger/search', 'GET', $input));

    return json_decode($response->getContent(), true)['results'];
}

beforeEach(function () {
    SearchControllerAuthGuard::$admin = true;
    bootSearchControllerDatabase();
    seedSearchControllerRecords();
});

test('navigator search returns tenant-scoped contracts for every ledger type', function () {
    $results = searchControllerResults(['query' => 'needle', 'limit' => 24]);

    expect($results)->toHaveCount(7)
        ->and(array_column($results, 'type'))->toBe([
            'Invoice',
            'Invoice Template',
            'Wallet',
            'Transaction',
            'Gateway',
            'Account',
            'Journal Entry',
        ])
        ->and(array_column($results, 'models'))->not->toContain(['foreign-invoice'])
        ->and($results[0])->toMatchArray([
            'label'  => 'INV-NEEDLE',
            'route'  => 'console.ledger.billing.invoices.index.details',
            'models' => ['invoice-uuid'],
        ])
        ->and($results[1]['description'])->toBe('Ledger invoice template')
        ->and($results[2]['label'])->toBe('wallet_public')
        ->and($results[3]['label'])->toBe('NEEDLE-TXN')
        ->and($results[4]['label'])->toBe('gateway_public')
        ->and($results[5]['label'])->toBe('NEEDLE-1000')
        ->and($results[6]['label'])->toBe('journal_public');
});

test('navigator search normalizes query aliases, type filters, limits, and empty input', function () {
    expect(searchControllerResults(['query' => '   ']))->toBe([])
        ->and(searchControllerResults(['q' => 'needle', 'types' => ' wallets, accounts ', 'limit' => 1]))
        ->toHaveCount(1)
        ->and(searchControllerResults(['q' => 'needle', 'types' => ['unknown']]))
        ->toHaveCount(7)
        ->and(searchControllerResults(['q' => 'needle', 'types' => new stdClass()]))
        ->toHaveCount(7)
        ->and(searchControllerResults(['q' => 'needle', 'types' => ['wallets'], 'limit' => 0]))
        ->toHaveCount(1);
});

test('navigator search escapes wildcard input instead of broadening the query', function () {
    expect(searchControllerResults(['query' => '%', 'types' => ['invoices']]))->toBe([])
        ->and(searchControllerResults(['query' => '_', 'types' => ['invoices']]))->toBe([]);
});

test('navigator search skips unauthorized types and safely handles an unknown internal type', function () {
    SearchControllerAuthGuard::$admin = false;
    expect(searchControllerResults(['query' => 'needle', 'types' => ['invoices']]))->toBe([]);

    $method = new ReflectionMethod(SearchController::class, 'searchType');
    $method->setAccessible(true);
    expect($method->invoke(new SearchController(), 'unknown', 'needle', 1))->toBeEmpty();
});
