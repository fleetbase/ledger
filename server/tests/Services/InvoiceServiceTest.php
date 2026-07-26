<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Ledger\Models\Account;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Models\InvoiceItem;
use Fleetbase\Ledger\Models\Journal;
use Fleetbase\Ledger\Models\Transaction;
use Fleetbase\Ledger\Notifications\InvoiceSent;
use Fleetbase\Ledger\Services\InvoiceService;
use Fleetbase\Ledger\Services\LedgerService;
use Fleetbase\TestSupport\LoggerManager;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Notification;

class InvoiceServiceLedgerSpy extends LedgerService
{
    public array $calls = [];

    public function createJournalEntry(
        Account $debitAccount,
        Account $creditAccount,
        int $amount,
        string $description = '',
        array $options = [],
    ): Journal {
        $this->calls[] = compact('debitAccount', 'creditAccount', 'amount', 'description', 'options');

        return new Journal(['amount' => $amount, 'description' => $description]);
    }
}

class InvoiceServiceOrder extends Order
{
    public array $testMeta = [];

    public function getMeta($key = null, $default = null)
    {
        return $key === null ? $this->testMeta : ($this->testMeta[$key] ?? $default);
    }
}

class InvoiceServiceEntity
{
    public function __construct(
        public ?string $name = null,
        public ?string $description = null,
        public ?int $price = null,
        public ?int $qty = null,
        public array $meta = [],
    ) {
    }

    public function getMeta(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }
}

class InvoiceServiceRelationStub
{
    public array $loaded = [];

    public function __construct(public mixed $serviceQuote = null)
    {
    }

    public function relationLoaded(string $relation): bool
    {
        return in_array($relation, $this->loaded, true);
    }

    public function load(string $relation): static
    {
        $this->loaded[] = $relation;

        return $this;
    }
}

class InvoiceServiceMailInvoice extends Invoice
{
    public bool $quietlySaved = false;

    public function loadMissing($relations)
    {
        return $this;
    }

    public function fresh($with = [])
    {
        return $this;
    }

    public function markAsSent(): void
    {
        $this->status  = 'sent';
        $this->sent_at = now();
    }

    public function saveQuietly(array $options = [])
    {
        $this->quietlySaved = true;

        return true;
    }
}

function bootInvoiceServiceDatabase(): void
{
    $capsule    = new Capsule(Container::getInstance());
    $dispatcher = new Dispatcher(Container::getInstance());
    $database   = tempnam(sys_get_temp_dir(), 'ledger-invoice-service-');
    $capsule->setEventDispatcher($dispatcher);
    Model::setEventDispatcher($dispatcher);
    Model::clearBootedModels();
    $capsule->addConnection([
        'driver'   => 'sqlite',
        'database' => $database,
        'prefix'   => '',
    ], 'testing');
    $capsule->addConnection([
        'driver'   => 'sqlite',
        'database' => $database,
        'prefix'   => '',
    ], 'mysql');
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('testing');
    Container::getInstance()->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstance('db');

    $schema = $capsule->getConnection('testing')->getSchemaBuilder();
    $schema->create('settings', function (Blueprint $table) {
        $table->increments('id');
        $table->string('key')->unique();
        $table->text('value')->nullable();
    });
    $schema->create('ledger_invoices', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('_key')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('customer_uuid')->nullable();
        $table->string('customer_type')->nullable();
        $table->string('order_uuid')->nullable();
        $table->string('transaction_uuid')->nullable();
        $table->string('template_uuid')->nullable();
        $table->string('number')->nullable()->unique();
        $table->date('date')->nullable();
        $table->date('due_date')->nullable();
        $table->bigInteger('subtotal')->default(0);
        $table->bigInteger('tax')->default(0);
        $table->bigInteger('total_amount')->default(0);
        $table->bigInteger('amount_paid')->default(0);
        $table->bigInteger('balance')->default(0);
        $table->string('currency')->nullable();
        $table->string('status')->default('draft');
        $table->text('notes')->nullable();
        $table->text('terms')->nullable();
        $table->text('meta')->nullable();
        $table->timestamp('sent_at')->nullable();
        $table->timestamp('paid_at')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('ledger_invoice_items', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('invoice_uuid');
        $table->text('description')->nullable();
        $table->integer('quantity')->default(1);
        $table->bigInteger('unit_price')->default(0);
        $table->bigInteger('amount')->default(0);
        $table->decimal('tax_rate', 8, 2)->default(0);
        $table->bigInteger('tax_amount')->default(0);
        $table->text('meta')->nullable();
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
        $table->text('description')->nullable();
        $table->boolean('is_system_account')->default(false);
        $table->bigInteger('balance')->default(0);
        $table->string('currency')->nullable();
        $table->string('status')->default('active');
        $table->text('meta')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('transactions', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('_key')->nullable();
        $table->string('company_uuid')->nullable();
        foreach (['owner_uuid', 'owner_type', 'customer_uuid', 'customer_type', 'payer_uuid', 'payer_type', 'payee_uuid', 'payee_type', 'subject_uuid', 'subject_type', 'context_uuid', 'context_type'] as $column) {
            $table->string($column)->nullable();
        }
        $table->bigInteger('amount')->default(0);
        $table->bigInteger('net_amount')->default(0);
        $table->string('currency')->nullable();
        $table->text('description')->nullable();
        $table->string('type')->nullable();
        $table->string('direction')->nullable();
        $table->string('status')->nullable();
        $table->string('settlement_status')->nullable();
        $table->string('payment_method')->nullable();
        $table->string('reference')->nullable();
        $table->timestamp('settled_at')->nullable();
        $table->bigInteger('settled_amount')->nullable();
        $table->string('settled_currency')->nullable();
        $table->text('meta')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    foreach (['orders', 'templates', 'customers'] as $tableName) {
        $schema->create($tableName, function (Blueprint $table) {
            $table->string('uuid')->primary();
            $table->softDeletes();
            $table->timestamps();
        });
    }
}

function invoiceServiceOrder(array $meta = []): InvoiceServiceOrder
{
    $order = new InvoiceServiceOrder();
    $order->forceFill([
        'uuid'          => 'order-invoice-service',
        'public_id'     => 'order_123',
        'company_uuid'  => 'company-invoice-service',
        'customer_uuid' => 'customer-invoice-service',
        'customer_type' => 'customer',
    ]);
    $order->testMeta = $meta;

    return $order;
}

function invoiceServiceInvoice(array $attributes = []): Invoice
{
    return Invoice::withoutEvents(function () use ($attributes) {
        $invoice = new Invoice();
        $invoice->forceFill(array_merge([
            'uuid'          => 'invoice-service-record',
            'public_id'     => 'invoice_record',
            'company_uuid'  => 'company-invoice-service',
            'customer_uuid' => 'customer-invoice-service',
            'customer_type' => 'customer',
            'number'        => 'INV-RECORD',
            'date'          => '2026-01-01',
            'currency'      => 'USD',
            'status'        => 'sent',
            'subtotal'      => 1000,
            'tax'           => 0,
            'total_amount'  => 1000,
            'amount_paid'   => 0,
            'balance'       => 1000,
        ], $attributes));
        $invoice->save();

        return $invoice;
    });
}

beforeEach(function () {
    bootInvoiceServiceDatabase();
    session(['company' => 'company-invoice-service']);
    Cache::flush();
    LoggerManager::$records = [];
});

test('order invoices use structured meta items plus delivery and service fees', function () {
    $service = new InvoiceService(new InvoiceServiceLedgerSpy());
    $order   = invoiceServiceOrder([
        'currency'     => 'EUR',
        'items'        => [
            ['name' => 'Crate', 'price' => 250, 'quantity' => 2, 'tax_rate' => 5, 'tax_amount' => 25],
            ['description' => 'Pallet', 'unit_price' => 300, 'qty' => 0],
        ],
        'delivery_fee' => 100,
        'service_fee'  => 50,
    ]);

    $invoice = $service->createFromOrder($order, [
        'number'           => 'INV-ORDER',
        'transaction_uuid' => 'purchase-rate-transaction',
        'template_uuid'    => 'template-one',
        'date'             => '2026-01-02',
        'due_date'         => '2026-02-02',
        'notes'            => 'Careful handling',
        'terms'            => 'Net 30',
    ]);
    $items = InvoiceItem::query()->where('invoice_uuid', $invoice->uuid)->orderBy('created_at')->get();

    expect($invoice->currency)->toBe('EUR')
        ->and($invoice->transaction_uuid)->toBe('purchase-rate-transaction')
        ->and($invoice->template_uuid)->toBe('template-one')
        ->and($invoice->subtotal)->toBe(950)
        ->and($invoice->tax)->toBe(25)
        ->and($invoice->total_amount)->toBe(975)
        ->and($invoice->balance)->toBe(975)
        ->and($items)->toHaveCount(4)
        ->and($items->pluck('description')->all())->toBe(['Crate', 'Pallet', 'Delivery fee', 'Service fee'])
        ->and($items[1]->quantity)->toBe(1);
});

test('order invoice item resolution prefers payload entities and has a total fallback', function () {
    $service = new InvoiceService(new InvoiceServiceLedgerSpy());
    $order   = invoiceServiceOrder(['items' => [['name' => 'Ignored meta item', 'price' => 999]]]);
    $payload = new class {
        public EloquentCollection $entities;
    };
    $payload->entities = new EloquentCollection([
        new InvoiceServiceEntity('Payload crate', null, 400, 2),
        new InvoiceServiceEntity(null, 'Meta-priced parcel', null, null, ['price' => 125, 'qty' => 0]),
        new InvoiceServiceEntity(null, null, 10, 1),
    ]);
    $order->setRelation('payload', $payload);

    $payloadInvoice = $service->createFromOrder($order, ['number' => 'INV-PAYLOAD']);

    expect($payloadInvoice->items()->pluck('description')->all())->toBe([
        'Payload crate',
        'Meta-priced parcel',
        'Item from order order_123',
    ])->and($payloadInvoice->total_amount)->toBe(935);

    $fallbackOrder = invoiceServiceOrder(['total' => 725]);
    $fallbackOrder->setAttribute('uuid', 'order-fallback');
    $fallback      = $service->createFromOrder($fallbackOrder, ['number' => 'INV-FALLBACK', 'currency' => 'MNT']);

    expect($fallback->items()->value('description'))->toBe('Delivery service — Order order_123')
        ->and($fallback->total_amount)->toBe(725)
        ->and($fallback->currency)->toBe('MNT');
});

test('purchase-rate invoices cover quote items totals loading and order fallback', function () {
    $service = new InvoiceService(new InvoiceServiceLedgerSpy());
    $order   = invoiceServiceOrder(['total' => 600]);

    $quote        = new InvoiceServiceRelationStub();
    $quote->items = new EloquentCollection([
        (object) ['amount' => 500, 'details' => 'Base fee', 'code' => 'base'],
        (object) ['amount' => 75, 'details' => null, 'code' => 'fuel'],
    ]);
    $purchaseRate = new InvoiceServiceRelationStub($quote);
    $invoice      = $service->createFromOrder($order, ['number' => 'INV-QUOTE'], $purchaseRate);

    expect($purchaseRate->loaded)->toBe(['serviceQuote.items'])
        ->and($invoice->items()->pluck('description')->all())->toBe(['Base fee', 'fuel'])
        ->and($invoice->total_amount)->toBe(575);

    $emptyQuote         = new InvoiceServiceRelationStub();
    $emptyQuote->items  = new EloquentCollection();
    $emptyQuote->amount = 350;
    $loadedRate         = new InvoiceServiceRelationStub($emptyQuote);
    $loadedRate->loaded = ['serviceQuote'];
    $flat               = $service->createFromOrder($order, ['number' => 'INV-FLAT'], $loadedRate);

    expect($emptyQuote->loaded)->toBe(['items'])
        ->and($flat->total_amount)->toBe(350);

    $noQuote  = new InvoiceServiceRelationStub();
    $fallback = $service->createFromOrder($order, ['number' => 'INV-NO-QUOTE'], $noQuote);
    expect($fallback->total_amount)->toBe(600);
});

test('recording partial and final payments persists transactions accounts and journals', function () {
    $ledger  = new InvoiceServiceLedgerSpy();
    $service = new InvoiceService($ledger);
    $invoice = invoiceServiceInvoice();

    $partial = $service->recordPayment($invoice, 400, [
        'payment_method' => 'bank_transfer',
        'reference'      => 'BANK-1',
        'memo'           => 'first instalment',
    ]);
    $firstTransaction = Transaction::query()->firstOrFail();

    expect($partial->status)->toBe('partial')
        ->and($partial->amount_paid)->toBe(400)
        ->and($partial->balance)->toBe(600)
        ->and($partial->transaction_uuid)->toBe($firstTransaction->uuid)
        ->and($firstTransaction->direction)->toBe('credit')
        ->and($firstTransaction->settlement_status)->toBe(Transaction::SETTLEMENT_STATUS_PAID)
        ->and($firstTransaction->payment_method)->toBe('bank_transfer')
        ->and($firstTransaction->reference)->toBe('BANK-1')
        ->and(Account::query()->pluck('code')->sort()->values()->all())->toBe(['AR-DEFAULT', 'CASH-DEFAULT'])
        ->and($ledger->calls)->toHaveCount(1)
        ->and($ledger->calls[0]['options']['transaction_uuid'])->toBe($firstTransaction->uuid)
        ->and($ledger->calls[0]['options']['memo'])->toBe('first instalment');

    $originalTransactionUuid = $partial->transaction_uuid;
    $paid                    = $service->recordPayment($partial, 600);

    expect($paid->status)->toBe('paid')
        ->and($paid->amount_paid)->toBe(1000)
        ->and($paid->balance)->toBe(0)
        ->and($paid->transaction_uuid)->toBe($originalTransactionUuid)
        ->and(Transaction::query()->count())->toBe(2)
        ->and(Account::query()->count())->toBe(2)
        ->and($ledger->calls[1]['options']['type'])->toBe('invoice_payment');
});

test('revenue recognition provisions accounts and skips zero value invoices', function () {
    $ledger  = new InvoiceServiceLedgerSpy();
    $service = new InvoiceService($ledger);
    $invoice = invoiceServiceInvoice(['total_amount' => 850, 'balance' => 850]);

    $service->recogniseRevenue($invoice);

    expect(Account::query()->pluck('code')->sort()->values()->all())->toBe(['AR-DEFAULT', 'REV-DEFAULT'])
        ->and($ledger->calls)->toHaveCount(1)
        ->and($ledger->calls[0]['amount'])->toBe(850)
        ->and($ledger->calls[0]['options']['journal_type'])->toBe('revenue_recognition')
        ->and($ledger->calls[0]['options']['meta']['invoice_uuid'])->toBe($invoice->uuid);

    $service->recogniseRevenue(invoiceServiceInvoice([
        'uuid'         => 'invoice-zero', 'public_id' => 'invoice_zero', 'number' => 'INV-ZERO',
        'total_amount' => 0, 'balance' => 0,
    ]));
    expect($ledger->calls)->toHaveCount(1);
});

test('sending invoices validates terminal states and customer email precedence', function () {
    $notificationFake = Notification::fake();
    Container::getInstance()->instance(Illuminate\Contracts\Notifications\Dispatcher::class, $notificationFake);
    $service = new InvoiceService(new InvoiceServiceLedgerSpy());

    foreach (['paid', 'void', 'cancelled'] as $status) {
        $terminal = new InvoiceServiceMailInvoice(['status' => $status]);
        $terminal->setRelation('customer', (object) ['email' => 'customer@example.test']);
        expect(fn () => $service->send($terminal))->toThrow(InvalidArgumentException::class);
    }

    $missing = new InvoiceServiceMailInvoice(['status' => 'draft']);
    $missing->setRelation('customer', (object) ['name' => 'No email']);
    expect(fn () => $service->send($missing))->toThrow(InvalidArgumentException::class);

    $absent = new InvoiceServiceMailInvoice(['status' => 'draft']);
    $absent->setRelation('customer', null);
    expect(fn () => $service->send($absent))->toThrow(InvalidArgumentException::class);

    foreach ([
        ['email' => 'primary@example.test', 'contact_email' => 'contact@example.test'],
        ['contact_email' => 'contact@example.test', 'billing_email' => 'billing@example.test'],
        ['billing_email' => 'billing@example.test'],
    ] as $customer) {
        $invoice = new InvoiceServiceMailInvoice(['status' => 'draft']);
        $invoice->setRelation('customer', (object) $customer);
        $result = $service->send($invoice);
        expect($result)->toBe($invoice)->and($invoice->status)->toBe('sent');
    }

    Notification::assertSentOnDemandTimes(InvoiceSent::class, 3);
});

test('automatic sending is opt in and restores invoice state after delivery failures', function () {
    $service = new class(new InvoiceServiceLedgerSpy()) extends InvoiceService {
        public bool $throw = false;
        public int $calls  = 0;

        public function send(Invoice $invoice): Invoice
        {
            $this->calls++;
            $invoice->status  = 'sent';
            $invoice->sent_at = now();

            if ($this->throw) {
                throw new RuntimeException('mail transport unavailable');
            }

            return $invoice;
        }
    };
    $invoice = new InvoiceServiceMailInvoice(['uuid' => 'auto-send-invoice', 'status' => 'draft']);

    expect($service->autoSendOnCreation($invoice))->toBe($invoice)
        ->and($service->calls)->toBe(0);

    Capsule::table('settings')->insert([
        'key'   => 'company.company-invoice-service.ledger.invoice-settings',
        'value' => json_encode(['auto_send_on_creation' => true]),
    ]);
    Cache::flush();
    expect($service->autoSendOnCreation($invoice))->toBe($invoice)
        ->and($service->calls)->toBe(1)
        ->and($invoice->status)->toBe('sent');

    $invoice->status  = 'draft';
    $invoice->sent_at = null;
    $service->throw   = true;
    expect($service->autoSendOnCreation($invoice))->toBe($invoice)
        ->and($invoice->status)->toBe('draft')
        ->and($invoice->sent_at)->toBeNull()
        ->and($invoice->quietlySaved)->toBeTrue()
        ->and(LoggerManager::$records)->toHaveCount(1)
        ->and(LoggerManager::$records[0]['context']['error'])->toBe('mail transport unavailable');
});
