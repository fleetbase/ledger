<?php

use Fleetbase\Ledger\Models\Account;
use Fleetbase\Ledger\Models\Journal;
use Fleetbase\Ledger\Observers\OrderAccountingObserver;
use Fleetbase\Ledger\Services\LedgerService;
use Fleetbase\Ledger\Services\RevenueLifecycleService;
use Fleetbase\TestSupport\LoggerManager;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

class OrderAccountingLedgerSpy extends LedgerService
{
    public array $calls          = [];
    public ?Throwable $exception = null;

    public function __construct()
    {
    }

    public function createJournalEntry(
        Account $debitAccount,
        Account $creditAccount,
        int $amount,
        string $description = '',
        array $options = [],
    ): Journal {
        $this->calls[] = compact('debitAccount', 'creditAccount', 'amount', 'description', 'options');

        if ($this->exception) {
            throw $this->exception;
        }

        return new Journal(['uuid' => 'observer-journal']);
    }
}

class OrderAccountingRevenueSpy extends RevenueLifecycleService
{
    public array $calls = [];

    public function __construct()
    {
    }

    public function handleOrderCanceled($order, string $previousStatus, string $currentStatus, string $reason = 'order_canceled'): void
    {
        $this->calls[] = ['canceled', $order->uuid, $previousStatus, $currentStatus, $reason];
    }

    public function handleOrderRestored($order, string $previousStatus, string $currentStatus, string $reason = 'order_restored'): void
    {
        $this->calls[] = ['restored', $order->uuid, $previousStatus, $currentStatus, $reason];
    }

    public function handleOrderDeleted($order): void
    {
        $this->calls[] = ['deleted', $order->uuid];
    }

    public function handleOrderRestoredFromDelete($order): void
    {
        $this->calls[] = ['restored-from-delete', $order->uuid];
    }
}

class OrderAccountingOrder
{
    public string $uuid           = 'order-accounting';
    public string $public_id      = 'order_public';
    public string $company_uuid   = 'company-order-accounting';
    public string $type           = 'storefront';
    public string $status         = 'active';
    public bool $statusChanged    = true;
    public string $originalStatus = 'created';
    public array $meta            = [
        'currency' => 'MNT',
        'total'    => 12500,
        'seed'     => 'demo',
        'seed_id'  => 'seed-1',
    ];

    public function getMeta(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }

    public function hasMeta(string $key): bool
    {
        return array_key_exists($key, $this->meta);
    }

    public function wasChanged(string $key): bool
    {
        return $key === 'status' && $this->statusChanged;
    }

    public function getOriginal(string $key): mixed
    {
        return $key === 'status' ? $this->originalStatus : null;
    }
}

function bootOrderAccountingObserverDatabase(): void
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
    session(['company' => 'company-order-accounting']);

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
        $table->string('currency')->nullable();
        $table->string('status')->default('active');
        $table->text('meta')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('ledger_journals', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('type');
        $table->text('meta')->nullable();
        $table->softDeletes();
    });
}

beforeEach(function () {
    bootOrderAccountingObserverDatabase();
    LoggerManager::$records = [];
});

test('storefront creation provisions default accounts and posts the complete sale journal contract', function () {
    $ledger  = new OrderAccountingLedgerSpy();
    $revenue = new OrderAccountingRevenueSpy();
    $order   = new OrderAccountingOrder();

    (new OrderAccountingObserver($ledger, $revenue))->created($order);

    expect($ledger->calls)->toHaveCount(1)
        ->and($ledger->calls[0]['debitAccount']->code)->toBe('CASH-DEFAULT')
        ->and($ledger->calls[0]['creditAccount']->code)->toBe('REV-DEFAULT')
        ->and($ledger->calls[0]['amount'])->toBe(12500)
        ->and($ledger->calls[0]['description'])->toBe('Storefront sale - Order order_public')
        ->and($ledger->calls[0]['options'])->toMatchArray([
            'company_uuid' => 'company-order-accounting',
            'currency'     => 'MNT',
            'journal_type' => 'storefront_sale',
            'meta'         => [
                'order_uuid'   => 'order-accounting',
                'order_id'     => 'order_public',
                'subject_uuid' => 'order-accounting',
                'subject_type' => OrderAccountingOrder::class,
                'seed'         => 'demo',
                'seed_id'      => 'seed-1',
            ],
        ])
        ->and(Account::query()->pluck('code')->sort()->values()->all())
        ->toBe(['CASH-DEFAULT', 'REV-DEFAULT']);
});

test('storefront creation skips irrelevant, zero-value, and previously recorded orders', function () {
    $ledger   = new OrderAccountingLedgerSpy();
    $observer = new OrderAccountingObserver($ledger, new OrderAccountingRevenueSpy());
    $order    = new OrderAccountingOrder();

    $order->type = 'fleetops';
    $observer->created($order);

    $order->type          = 'storefront';
    $order->meta['total'] = 0;
    $observer->created($order);

    Capsule::table('ledger_journals')->insert([
        'uuid' => 'existing-storefront-journal',
        'type' => 'storefront_sale',
        'meta' => json_encode(['order_uuid' => $order->uuid]),
    ]);
    $order->meta['total'] = 100;
    $observer->created($order);

    expect($ledger->calls)->toBe([])
        ->and(collect(LoggerManager::$records)->pluck('message')->all())
        ->toContain('[Ledger] OrderAccountingObserver: skipping Storefront order with zero total.');
});

test('storefront accounting failures are contained and audited without aborting order creation', function () {
    $ledger            = new OrderAccountingLedgerSpy();
    $ledger->exception = new RuntimeException('journal database unavailable');
    $observer          = new OrderAccountingObserver($ledger, new OrderAccountingRevenueSpy());

    $observer->created(new OrderAccountingOrder());

    expect($ledger->calls)->toHaveCount(1)
        ->and(collect(LoggerManager::$records)->pluck('message')->all())
        ->toContain('[Ledger] OrderAccountingObserver: failed to create Storefront sale journal.');
});

test('order lifecycle transitions delegate cancellation restoration deletion and restore contracts', function () {
    $revenue  = new OrderAccountingRevenueSpy();
    $observer = new OrderAccountingObserver(new OrderAccountingLedgerSpy(), $revenue);
    $order    = new OrderAccountingOrder();

    $order->statusChanged = false;
    $observer->updated($order);

    $order->statusChanged  = true;
    $order->originalStatus = '  ACTIVE ';
    $order->status         = ' Cancelled ';
    $observer->updated($order);

    $order->originalStatus = 'ORDER_CANCELED';
    $order->status         = 'Processing';
    $observer->updated($order);

    $order->originalStatus = 'created';
    $order->status         = 'processing';
    $observer->updated($order);

    $observer->deleted($order);

    $order->status = 'canceled';
    $observer->restored($order);
    $order->status = 'active';
    $observer->restored($order);

    expect($revenue->calls)->toBe([
        ['canceled', 'order-accounting', 'active', 'cancelled', 'order_canceled'],
        ['restored', 'order-accounting', 'order_canceled', 'processing', 'order_restored'],
        ['deleted', 'order-accounting'],
        ['restored-from-delete', 'order-accounting'],
    ]);
});
