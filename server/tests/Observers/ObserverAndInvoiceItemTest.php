<?php

use Fleetbase\Ledger\Events\InvoiceCreated;
use Fleetbase\Ledger\Events\InvoicePaid;
use Fleetbase\Ledger\Http\Resources\v1\InvoiceItem as InvoiceItemResource;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Observers\CompanyObserver;
use Fleetbase\Ledger\Observers\InvoiceObserver;
use Fleetbase\Ledger\Observers\UserObserver;
use Fleetbase\Ledger\Seeders\LedgerSeeder;
use Fleetbase\Ledger\Services\RevenueLifecycleService;
use Fleetbase\Ledger\Services\WalletService;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Fleetbase\TestSupport\EventRecorder;
use Fleetbase\TestSupport\LoggerManager;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;

class ObserverWalletService extends WalletService
{
    public array $calls          = [];
    public ?Throwable $exception = null;

    public function __construct()
    {
    }

    public function provisionUserWallet(User $user): ?Fleetbase\Ledger\Models\Wallet
    {
        $this->calls[] = $user->uuid;
        if ($this->exception) {
            throw $this->exception;
        }

        return null;
    }
}

class CompanyObserverWalletService extends WalletService
{
    public array $calls          = [];
    public ?Throwable $exception = null;

    public function __construct()
    {
    }

    public function provisionCompanyWallets(Company $company): EloquentCollection
    {
        $this->calls[] = $company->uuid;
        if ($this->exception) {
            throw $this->exception;
        }

        return new EloquentCollection();
    }
}

class CompanyObserverSeeder extends LedgerSeeder
{
    public array $calls          = [];
    public ?Throwable $exception = null;

    public function runForCompany(string $companyUuid): void
    {
        $this->calls[] = $companyUuid;
        if ($this->exception) {
            throw $this->exception;
        }
    }
}

class CompanyObserverProbe extends CompanyObserver
{
    public function __construct(WalletService $walletService, private CompanyObserverSeeder $seeder)
    {
        parent::__construct($walletService);
    }

    protected function makeLedgerSeeder(): LedgerSeeder
    {
        return $this->seeder;
    }
}

class ObserverUser extends User
{
    public bool $companyChanged = true;

    public function wasChanged($attributes = null): bool
    {
        return $attributes === 'company_uuid' && $this->companyChanged;
    }
}

class ObserverRevenueService extends RevenueLifecycleService
{
    public array $calls = [];

    public function __construct()
    {
    }

    public function handleInvoiceCanceled(Invoice $invoice, string $previousStatus, string $reason = 'invoice_cancelled'): void
    {
        $this->calls[] = ['canceled', $invoice->uuid, $previousStatus, $reason];
    }

    public function handleInvoiceDeleting(Invoice $invoice): void
    {
        $this->calls[] = ['deleting', $invoice->uuid];
    }

    public function handleInvoiceRestored(Invoice $invoice): void
    {
        $this->calls[] = ['restored', $invoice->uuid];
    }
}

class ObserverInvoice extends Invoice
{
    public bool $statusChanged     = true;
    public bool $testForceDeleting = false;
    public string $originalStatus  = 'sent';

    public function wasChanged($attributes = null): bool
    {
        return $attributes === 'status' && $this->statusChanged;
    }

    public function getOriginal($key = null, $default = null): mixed
    {
        return $key === 'status' ? $this->originalStatus : $default;
    }

    public function isForceDeleting(): bool
    {
        return $this->testForceDeleting;
    }
}

beforeEach(function () {
    EventRecorder::reset();
    LoggerManager::$records = [];
});

test('user observer provisions only company-scoped users on creation and assignment', function () {
    $service  = new ObserverWalletService();
    $observer = new UserObserver($service);
    $user     = new ObserverUser(['uuid' => 'user-observer']);

    $observer->created($user);
    $observer->updated($user);
    expect($service->calls)->toBe([]);

    $user->company_uuid = 'company-observer';
    $observer->created($user);
    $user->companyChanged = false;
    $observer->updated($user);
    $user->companyChanged = true;
    $observer->updated($user);
    expect($service->calls)->toBe(['user-observer', 'user-observer']);
});

test('company observer independently provisions accounts and system wallets', function () {
    $wallets  = new CompanyObserverWalletService();
    $seeder   = new CompanyObserverSeeder();
    $observer = new CompanyObserverProbe($wallets, $seeder);
    $company  = new Company();
    $company->setRawAttributes(['uuid' => 'company-observer'], true);

    $observer->created($company);

    expect($seeder->calls)->toBe(['company-observer'])
        ->and($wallets->calls)->toBe(['company-observer'])
        ->and(LoggerManager::$records)->toBe([]);
});

test('company observer contains and audits account and wallet provisioning failures', function () {
    $wallets            = new CompanyObserverWalletService();
    $wallets->exception = new RuntimeException('wallet failure');
    $seeder             = new CompanyObserverSeeder();
    $seeder->exception  = new RuntimeException('account failure');
    $observer           = new CompanyObserverProbe($wallets, $seeder);
    $company            = new Company();
    $company->setRawAttributes(['uuid' => 'company-error'], true);

    $observer->created($company);

    expect($seeder->calls)->toBe(['company-error'])
        ->and($wallets->calls)->toBe(['company-error'])
        ->and(collect(LoggerManager::$records)->pluck('message')->all())->toBe([
            '[Ledger] Failed to seed default accounts for company company-error: account failure',
            '[Ledger] Failed to provision wallets for company company-error: wallet failure',
        ]);
});

test('company observer constructs the production Ledger seeder by default', function () {
    $observer = new CompanyObserver(new CompanyObserverWalletService());
    $method   = new ReflectionMethod($observer, 'makeLedgerSeeder');
    $method->setAccessible(true);

    expect($method->invoke($observer))->toBeInstanceOf(LedgerSeeder::class);
});

test('user observer contains and audits provisioning failures in both hooks', function () {
    $service            = new ObserverWalletService();
    $service->exception = new RuntimeException('wallet storage unavailable');
    $observer           = new UserObserver($service);
    $user               = new ObserverUser([
        'uuid'         => 'user-error',
        'company_uuid' => 'company-observer',
    ]);

    $observer->created($user);
    $observer->updated($user);

    expect($service->calls)->toHaveCount(2)
        ->and(collect(LoggerManager::$records)->pluck('message')->all())
        ->toBe([
            '[Ledger] Failed to provision wallet for user user-error: wallet storage unavailable',
            '[Ledger] Failed to provision wallet for user user-error: wallet storage unavailable',
        ]);
});

test('invoice observer dispatches lifecycle events and delegates terminal state changes', function () {
    $revenue  = new ObserverRevenueService();
    $observer = new InvoiceObserver($revenue);
    $invoice  = new ObserverInvoice();
    $invoice->forceFill([
        'uuid'   => 'invoice-observer',
        'status' => 'paid',
    ]);

    $observer->created($invoice);
    $observer->updated($invoice);
    expect(EventRecorder::$events[0])->toBeInstanceOf(InvoiceCreated::class)
        ->and(EventRecorder::$events[1])->toBeInstanceOf(InvoicePaid::class);

    $invoice->status = 'voided';
    $observer->updated($invoice);
    $invoice->statusChanged = false;
    $observer->updated($invoice);

    $invoice->testForceDeleting = true;
    $observer->deleting($invoice);
    $invoice->testForceDeleting = false;
    $observer->deleting($invoice);
    $observer->restored($invoice);

    expect($revenue->calls)->toBe([
        ['canceled', 'invoice-observer', 'sent', 'invoice_cancelled'],
        ['deleting', 'invoice-observer'],
        ['restored', 'invoice-observer'],
    ]);
});

test('invoice item resources retain quantity, tax, monetary, metadata, and ownership shapes', function () {
    $item = (object) [
        'id'           => 10,
        'uuid'         => 'invoice-item-uuid',
        'public_id'    => 'invoice_item_public',
        'invoice_uuid' => 'invoice-uuid',
        'description'  => 'Delivery',
        'quantity'     => 2,
        'unit_price'   => 1500,
        'amount'       => 3000,
        'tax_rate'     => 10.0,
        'tax_amount'   => 300,
        'meta'         => ['category' => 'service'],
        'created_at'   => '2026-07-01 10:00:00',
        'updated_at'   => '2026-07-02 10:00:00',
    ];

    $payload = (new InvoiceItemResource($item))->toArray(new Request());
    expect($payload)->toMatchArray([
        'public_id'   => 'invoice_item_public',
        'description' => 'Delivery',
        'quantity'    => 2,
        'unit_price'  => 1500,
        'amount'      => 3000,
        'tax_rate'    => 10.0,
        'tax_amount'  => 300,
        'meta'        => ['category' => 'service'],
    ]);
});
