<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Observers\PurchaseRateObserver;
use Fleetbase\Ledger\Services\InvoiceService;
use Fleetbase\TestSupport\LoggerManager;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

class PurchaseRateObserverInvoiceService extends InvoiceService
{
    public array $calls          = [];
    public ?Throwable $exception = null;

    public function __construct()
    {
    }

    public function createFromOrder(Order $order, array $options = [], ?object $purchaseRate = null): Invoice
    {
        $this->calls[] = [$order, $options, $purchaseRate];

        if ($this->exception) {
            throw $this->exception;
        }

        return new Invoice([
            'uuid'   => 'generated-invoice',
            'number' => 'INV-GENERATED',
        ]);
    }
}

function bootPurchaseRateObserverDatabase(): void
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
    session(['company' => 'company-purchase-rate']);

    $schema = $capsule->getConnection('testing')->getSchemaBuilder();
    $schema->create('ledger_invoices', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('order_uuid')->nullable();
        $table->string('status');
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('orders', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('payload_uuid')->nullable();
        $table->string('purchase_rate_uuid')->nullable();
        $table->softDeletes();
    });
}

function purchaseRateObserverOrder(array $attributes = []): Order
{
    $order = new Order();
    $order->forceFill(array_merge([
        'uuid'         => 'order-purchase-rate',
        'public_id'    => 'order_public',
        'company_uuid' => 'company-purchase-rate',
        'scheduled_at' => '2026-08-15 10:30:00',
        'meta'         => ['currency' => 'MNT'],
    ], $attributes));

    return $order;
}

function purchaseRateObserverRate(?Order $order, array $attributes = []): object
{
    return (object) array_merge([
        'uuid'             => 'purchase-rate-uuid',
        'payload_uuid'     => 'payload-uuid',
        'transaction_uuid' => 'transaction-uuid',
        'serviceQuote'     => (object) ['currency' => 'EUR'],
        'order'            => $order,
    ], $attributes);
}

beforeEach(function () {
    bootPurchaseRateObserverDatabase();
    LoggerManager::$records = [];
});

test('purchase rate creation voids prior drafts and creates the active invoice revision', function () {
    Capsule::table('ledger_invoices')->insert([
        ['uuid' => 'prior-draft', 'order_uuid' => 'order-purchase-rate', 'status' => 'draft'],
        ['uuid' => 'prior-sent', 'order_uuid' => 'order-purchase-rate', 'status' => 'sent'],
    ]);
    $service = new PurchaseRateObserverInvoiceService();
    $rate    = purchaseRateObserverRate(purchaseRateObserverOrder());

    (new PurchaseRateObserver($service))->created($rate);

    expect(Capsule::table('ledger_invoices')->where('uuid', 'prior-draft')->value('status'))->toBe('void')
        ->and(Capsule::table('ledger_invoices')->where('uuid', 'prior-sent')->value('status'))->toBe('sent')
        ->and($service->calls)->toHaveCount(1)
        ->and($service->calls[0][1]['currency'])->toBe('EUR')
        ->and($service->calls[0][1]['due_date']->format('Y-m-d H:i:s'))->toBe('2026-08-15 10:30:00')
        ->and($service->calls[0][1])->toMatchArray([
            'transaction_uuid' => 'transaction-uuid',
            'notes'            => 'Auto-generated from Fleet-Ops order order_public',
        ])
        ->and($service->calls[0][2])->toBe($rate);
});

test('purchase rate invoice defaults use order currency and a thirty day due date', function () {
    $service = new PurchaseRateObserverInvoiceService();
    $order   = purchaseRateObserverOrder(['scheduled_at' => null]);
    $rate    = purchaseRateObserverRate($order, ['serviceQuote' => null]);
    $before  = now()->addDays(30);

    (new PurchaseRateObserver($service))->created($rate);

    expect($service->calls[0][1]['currency'])->toBe('MNT')
        ->and($service->calls[0][1]['due_date']->between($before, now()->addDays(30)))->toBeTrue();
});

test('purchase rate creation reports unresolved orders without creating invoices', function () {
    $service = new PurchaseRateObserverInvoiceService();

    (new PurchaseRateObserver($service))->created(purchaseRateObserverRate(null));

    expect($service->calls)->toBe([])
        ->and(collect(LoggerManager::$records)->pluck('message')->all())
        ->toContain('[Ledger] PurchaseRateObserver: could not resolve order for purchase rate.');
});

test('purchase rate invoice failures are contained and audited', function () {
    $service            = new PurchaseRateObserverInvoiceService();
    $service->exception = new RuntimeException('invoice write failed');

    (new PurchaseRateObserver($service))->created(purchaseRateObserverRate(purchaseRateObserverOrder()));

    expect($service->calls)->toHaveCount(1)
        ->and(collect(LoggerManager::$records)->pluck('message')->all())
        ->toContain('[Ledger] PurchaseRateObserver: failed to create invoice.');
});
