<?php

use Fleetbase\Ledger\Console\Commands\ProvisionLedgerDefaults;
use Fleetbase\Ledger\Models\Wallet;
use Fleetbase\Ledger\Seeders\LedgerSeeder;
use Fleetbase\Ledger\Services\WalletService;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Console\Tester\CommandTester;

final class ProvisionLedgerSeederSpy extends LedgerSeeder
{
    public array $companies = [];
    public array $failures  = [];

    public function runForCompany(string $companyUuid): void
    {
        $this->companies[] = $companyUuid;

        if (isset($this->failures[$companyUuid])) {
            throw new RuntimeException($this->failures[$companyUuid]);
        }
    }
}

final class ProvisionWalletServiceSpy extends WalletService
{
    public array $companies       = [];
    public array $users           = [];
    public array $companyFailures = [];
    public array $userFailures    = [];

    public function __construct()
    {
    }

    public function provisionCompanyWallets(Company $company): EloquentCollection
    {
        $this->companies[] = $company->uuid;

        if (isset($this->companyFailures[$company->uuid])) {
            throw new RuntimeException($this->companyFailures[$company->uuid]);
        }

        return new EloquentCollection();
    }

    public function provisionUserWallet(User $user): ?Wallet
    {
        $this->users[] = $user->uuid;

        if (isset($this->userFailures[$user->uuid])) {
            throw new RuntimeException($this->userFailures[$user->uuid]);
        }

        return null;
    }
}

final class ProvisionLedgerDefaultsProbe extends ProvisionLedgerDefaults
{
    public function __construct(private ProvisionLedgerSeederSpy $seeder)
    {
        parent::__construct();
    }

    protected function makeLedgerSeeder(): LedgerSeeder
    {
        return $this->seeder;
    }
}

function bootProvisionLedgerDatabase(): void
{
    $capsule = new Capsule(Container::getInstance());
    $config  = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
    $capsule->addConnection($config, 'testing');
    $capsule->addConnection($config, 'mysql');
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('testing');
    Container::getInstance()->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstance('db');
    Model::clearBootedModels();

    Capsule::schema('mysql')->create('companies', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('name')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    Capsule::schema('mysql')->create('users', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
}

function provisionLedgerDefaultsTester(
    ProvisionLedgerSeederSpy $seeder,
    ProvisionWalletServiceSpy $walletService,
): CommandTester {
    Container::getInstance()->instance(WalletService::class, $walletService);
    $command = new ProvisionLedgerDefaultsProbe($seeder);
    $command->setLaravel(Container::getInstance());

    return new CommandTester($command);
}

beforeEach(function () {
    bootProvisionLedgerDatabase();
});

test('ledger provisioning reports an empty installation without invoking dependencies', function () {
    $seeder  = new ProvisionLedgerSeederSpy();
    $wallets = new ProvisionWalletServiceSpy();
    $tester  = provisionLedgerDefaultsTester($seeder, $wallets);

    expect($tester->execute([]))->toBe(0)
        ->and($seeder->companies)->toBe([])
        ->and($wallets->companies)->toBe([])
        ->and($wallets->users)->toBe([])
        ->and($tester->getDisplay())->toContain('No companies found to provision.');
});

test('ledger provisioning continues across account company wallet and user failures', function () {
    Capsule::connection('mysql')->table('companies')->insert([
        ['uuid' => 'company-1', 'name' => 'One'],
        ['uuid' => 'company-2', 'name' => 'Two'],
    ]);
    Capsule::connection('mysql')->table('users')->insert([
        ['uuid' => 'user-1', 'company_uuid' => 'company-1', 'name' => 'User One'],
        ['uuid' => 'user-2', 'company_uuid' => 'company-2', 'name' => 'User Two'],
        ['uuid' => 'user-unscoped', 'company_uuid' => null, 'name' => 'No Company'],
    ]);
    $seeder                                = new ProvisionLedgerSeederSpy();
    $seeder->failures['company-2']         = 'account database unavailable';
    $wallets                               = new ProvisionWalletServiceSpy();
    $wallets->companyFailures['company-1'] = 'company wallet failed';
    $wallets->userFailures['user-2']       = 'user wallet failed';
    $tester                                = provisionLedgerDefaultsTester($seeder, $wallets);

    expect($tester->execute([]))->toBe(1)
        ->and($seeder->companies)->toBe(['company-1', 'company-2'])
        ->and($wallets->companies)->toBe(['company-1', 'company-2'])
        ->and($wallets->users)->toBe(['user-1', 'user-2'])
        ->and($tester->getDisplay())
        ->toContain('Provisioning 2 company/companies')
        ->toContain('Accounts failed for company company-2: account database unavailable')
        ->toContain('Company wallets failed for company-1: company wallet failed')
        ->toContain('User wallet failed for user-2: user wallet failed')
        ->toContain('Chart of accounts provisioned for 1 company/companies.')
        ->toContain('System wallets provisioned for 1 company/companies.')
        ->toContain('Personal wallets provisioned for 1 user(s).')
        ->toContain('3 error(s) occurred');
});

test('accounts-only provisioning scopes the company and skips every wallet operation', function () {
    Capsule::connection('mysql')->table('companies')->insert([
        ['uuid' => 'company-1', 'name' => 'One'],
        ['uuid' => 'company-2', 'name' => 'Two'],
    ]);
    $seeder  = new ProvisionLedgerSeederSpy();
    $wallets = new ProvisionWalletServiceSpy();
    $tester  = provisionLedgerDefaultsTester($seeder, $wallets);

    expect($tester->execute(['--company' => 'company-2', '--accounts-only' => true]))->toBe(0)
        ->and($seeder->companies)->toBe(['company-2'])
        ->and($wallets->companies)->toBe([])
        ->and($wallets->users)->toBe([])
        ->and($tester->getDisplay())
        ->toContain('Chart of accounts provisioned for 1 company/companies.')
        ->not->toContain('System wallets provisioned');
});

test('wallets-only provisioning reports the no-user path and skips account seeding', function () {
    Capsule::connection('mysql')->table('companies')->insert([
        'uuid' => 'company-1',
        'name' => 'One',
    ]);
    $seeder  = new ProvisionLedgerSeederSpy();
    $wallets = new ProvisionWalletServiceSpy();
    $tester  = provisionLedgerDefaultsTester($seeder, $wallets);

    expect($tester->execute(['--company' => 'company-1', '--wallets-only' => true]))->toBe(0)
        ->and($seeder->companies)->toBe([])
        ->and($wallets->companies)->toBe(['company-1'])
        ->and($tester->getDisplay())
        ->toContain('No users found to provision wallets for.')
        ->toContain('System wallets provisioned for 1 company/companies.')
        ->not->toContain('Chart of accounts provisioned');
});

test('provisioning command constructs the production ledger seeder by default', function () {
    $command = new ProvisionLedgerDefaults();
    $method  = new ReflectionMethod($command, 'makeLedgerSeeder');
    $method->setAccessible(true);

    expect($method->invoke($command))->toBeInstanceOf(LedgerSeeder::class);
});
