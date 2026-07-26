<?php

use Fleetbase\Ledger\Models\Account;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Models\Journal;
use Fleetbase\Ledger\Models\Transaction;
use Fleetbase\Ledger\Services\LedgerService;
use Fleetbase\Ledger\Services\RevenueLifecycleService;
use Fleetbase\TestSupport\LoggerManager;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

class RevenueLifecycleOrder
{
    public function __construct(
        public string $uuid,
        public string $public_id,
        public ?string $transaction_uuid = null,
        public string $status = 'active',
        public mixed $deleted_at = null,
        public array $meta = [],
    ) {
    }

    public function hasMeta(string $key): bool
    {
        return array_key_exists($key, $this->meta);
    }

    public function getMeta(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }
}

class RevenueLifecycleLedgerSpy extends LedgerService
{
    public array $calls = [];

    public function createJournalEntry(
        Account $debitAccount,
        Account $creditAccount,
        int $amount,
        string $description = '',
        array $options = [],
    ): Journal {
        $this->calls[]   = compact('debitAccount', 'creditAccount', 'amount', 'description', 'options');
        static $sequence = 0;
        $sequence++;

        return Journal::create([
            'uuid'                => sprintf('60000000-0000-4000-8000-%012d', $sequence),
            'public_id'           => 'journal_correction_' . $sequence,
            'company_uuid'        => $options['company_uuid'],
            'debit_account_uuid'  => $debitAccount->uuid,
            'credit_account_uuid' => $creditAccount->uuid,
            'amount'              => $amount,
            'currency'            => $options['currency'],
            'description'         => $description,
            'type'                => $options['journal_type'],
            'status'              => 'posted',
            'entry_date'          => $options['entry_date'],
            'meta'                => $options['meta'],
        ]);
    }
}

function bootRevenueLifecycleDatabase(): void
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
        $table->string('name');
        $table->string('code');
        $table->string('type');
        $table->bigInteger('balance')->default(0);
        $table->string('currency')->nullable();
        $table->string('status')->default('active');
        $table->text('meta')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('ledger_journals', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('_key')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('transaction_uuid')->nullable();
        $table->string('debit_account_uuid')->nullable();
        $table->string('credit_account_uuid')->nullable();
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
    $schema->create('ledger_invoices', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('company_uuid')->nullable();
        $table->string('order_uuid')->nullable();
        $table->string('transaction_uuid')->nullable();
        $table->string('number')->nullable();
        $table->bigInteger('amount_paid')->default(0);
        $table->bigInteger('total_amount')->default(0);
        $table->bigInteger('balance')->default(0);
        $table->string('currency')->nullable();
        $table->string('status');
        $table->text('meta')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('transactions', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('company_uuid')->nullable();
        $table->string('type')->nullable();
        $table->string('direction')->nullable();
        $table->string('status');
        $table->timestamp('voided_at')->nullable();
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
    $schema->create('orders', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->softDeletes();
        $table->timestamps();
    });
}

function revenueAccount(string $uuid, string $type): Account
{
    return Account::create([
        'uuid'         => $uuid,
        'public_id'    => $uuid . '-public',
        'company_uuid' => 'company-revenue',
        'name'         => ucfirst($uuid),
        'code'         => strtoupper($uuid),
        'type'         => $type,
        'currency'     => 'USD',
        'status'       => 'active',
    ]);
}

function revenueJournal(
    string $uuid,
    Account $debit,
    Account $credit,
    string $type,
    int $amount,
    array $meta,
): Journal {
    return Journal::create([
        'uuid'                => $uuid,
        'public_id'           => $uuid . '-public',
        'company_uuid'        => 'company-revenue',
        'debit_account_uuid'  => $debit->uuid,
        'credit_account_uuid' => $credit->uuid,
        'amount'              => $amount,
        'currency'            => 'USD',
        'description'         => ucfirst($type),
        'type'                => $type,
        'status'              => 'posted',
        'entry_date'          => '2026-01-01',
        'meta'                => $meta,
    ]);
}

function insertRevenueTransaction(string $uuid, string $status = 'success', array $meta = [], mixed $voidedAt = null): void
{
    Capsule::table('transactions')->insert([
        'uuid'         => $uuid,
        'public_id'    => $uuid . '-public',
        'company_uuid' => 'company-revenue',
        'type'         => 'sale',
        'direction'    => 'credit',
        'status'       => $status,
        'voided_at'    => $voidedAt,
        'meta'         => json_encode($meta),
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
}

function insertRevenueInvoice(
    string $uuid,
    string $status,
    string $orderUuid,
    ?string $transactionUuid = null,
    int $amountPaid = 0,
    array $meta = [],
    mixed $deletedAt = null,
): Invoice {
    Capsule::table('ledger_invoices')->insert([
        'uuid'             => $uuid,
        'public_id'        => $uuid . '-public',
        'company_uuid'     => 'company-revenue',
        'order_uuid'       => $orderUuid,
        'transaction_uuid' => $transactionUuid,
        'number'           => strtoupper($uuid),
        'amount_paid'      => $amountPaid,
        'total_amount'     => 1000,
        'balance'          => 1000 - $amountPaid,
        'currency'         => 'USD',
        'status'           => $status,
        'meta'             => json_encode($meta),
        'deleted_at'       => $deletedAt,
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);

    return Invoice::withTrashed()
        ->without(['customer', 'items', 'template', 'order', 'order.trackingNumber'])
        ->findOrFail($uuid);
}

beforeEach(function () {
    bootRevenueLifecycleDatabase();
    session(['company' => 'company-revenue']);
    LoggerManager::$records = [];
});

test('order cancellation reverses storefront and invoice revenue and voids unpaid sources idempotently', function () {
    $cash     = revenueAccount('cash', Account::TYPE_ASSET);
    $revenue  = revenueAccount('revenue', Account::TYPE_REVENUE);
    $order    = new RevenueLifecycleOrder('order-one', 'order_one', 'order-transaction');
    $ledger   = new RevenueLifecycleLedgerSpy();
    $service  = new RevenueLifecycleService($ledger);
    insertRevenueTransaction('order-transaction');
    $invoice = insertRevenueInvoice('invoice-one', 'sent', $order->uuid, 'invoice-transaction');
    insertRevenueTransaction('invoice-transaction');
    $storefrontSale = revenueJournal('storefront-sale', $cash, $revenue, 'storefront_sale', 1000, [
        'order_uuid' => $order->uuid, 'seed' => 'storefront', 'invoice_uuid' => $invoice->uuid,
    ]);
    $invoiceRevenue = revenueJournal('invoice-revenue', $cash, $revenue, 'revenue_recognition', 1000, [
        'invoice_uuid' => $invoice->uuid,
    ]);

    $service->handleOrderCanceled($order, 'completed', 'cancelled', 'customer_cancelled');

    $invoice->refresh();
    $orderTransaction   = Transaction::query()->findOrFail('order-transaction');
    $invoiceTransaction = Transaction::query()->findOrFail('invoice-transaction');
    expect($invoice->status)->toBe('cancelled')
        ->and($invoice->meta['revenue_lifecycle_previous_status'])->toBe('sent')
        ->and($orderTransaction->status)->toBe(Transaction::STATUS_VOIDED)
        ->and($invoiceTransaction->status)->toBe(Transaction::STATUS_VOIDED)
        ->and($ledger->calls)->toHaveCount(2)
        ->and($ledger->calls[0]['debitAccount']->is($revenue))->toBeTrue()
        ->and($ledger->calls[0]['options']['meta']['seed'])->toBe('storefront')
        ->and($ledger->calls[0]['options']['meta']['reverses_journal_uuid'])->toBe($storefrontSale->uuid)
        ->and(Journal::query()->whereIn('type', [
            'storefront_sale_reversal', 'revenue_recognition_reversal',
        ])->count())->toBe(2);

    $service->handleOrderCanceled($order, 'completed', 'cancelled', 'duplicate_delivery');
    expect($ledger->calls)->toHaveCount(2);
});

test('order restoration reinstates reversals and restores invoices and transactions', function () {
    $cash    = revenueAccount('cash', Account::TYPE_ASSET);
    $revenue = revenueAccount('revenue', Account::TYPE_REVENUE);
    $order   = new RevenueLifecycleOrder(
        'order-restore',
        'order_restore',
        'order-restore-transaction',
        'active',
        null,
        ['seed_id' => 'order-seed']
    );
    insertRevenueTransaction('order-restore-transaction', Transaction::STATUS_VOIDED, [
        'revenue_lifecycle_voided'          => true,
        'revenue_lifecycle_previous_status' => 'success',
    ], now());
    $invoice = insertRevenueInvoice('invoice-restore', 'cancelled', $order->uuid, 'invoice-restore-transaction', meta: [
        'revenue_lifecycle_status_changed'  => true,
        'revenue_lifecycle_previous_status' => 'sent',
    ]);
    insertRevenueTransaction('invoice-restore-transaction', Transaction::STATUS_VOIDED, [
        'revenue_lifecycle_voided'          => true,
        'revenue_lifecycle_previous_status' => 'pending',
    ], now());
    revenueJournal('storefront-reversal', $revenue, $cash, 'storefront_sale_reversal', 500, [
        'order_uuid'            => $order->uuid,
        'reverses_journal_uuid' => 'storefront-original',
    ]);
    revenueJournal('invoice-reversal', $revenue, $cash, 'revenue_recognition_reversal', 500, [
        'order_uuid'            => $order->uuid,
        'invoice_uuid'          => $invoice->uuid,
        'reverses_journal_uuid' => 'invoice-original',
    ]);

    $ledger  = new RevenueLifecycleLedgerSpy();
    $service = new RevenueLifecycleService($ledger);
    $service->handleOrderRestored($order, 'cancelled', 'active', 'operator_restore');

    expect($ledger->calls)->toHaveCount(2)
        ->and($ledger->calls[0]['options']['journal_type'])->toBe('storefront_sale_reinstatement')
        ->and($ledger->calls[0]['options']['meta']['seed_id'])->toBe('order-seed')
        ->and($invoice->fresh()->status)->toBe('sent')
        ->and(Transaction::query()->findOrFail('order-restore-transaction')->status)->toBe('success')
        ->and(Transaction::query()->findOrFail('invoice-restore-transaction')->status)->toBe(Transaction::STATUS_VOIDED);

    $service->handleOrderRestored($order, 'cancelled', 'active', 'duplicate_restore');
    expect($ledger->calls)->toHaveCount(2);
});

test('paid invoices are flagged for review while open invoice deletion reverses and voids', function () {
    $cash    = revenueAccount('cash', Account::TYPE_ASSET);
    $revenue = revenueAccount('revenue', Account::TYPE_REVENUE);
    $ledger  = new RevenueLifecycleLedgerSpy();
    $service = new RevenueLifecycleService($ledger);

    $open = insertRevenueInvoice('invoice-delete', 'draft', 'order-delete', 'transaction-delete');
    insertRevenueTransaction('transaction-delete');
    revenueJournal('invoice-delete-revenue', $cash, $revenue, 'revenue_recognition', 600, [
        'invoice_uuid' => $open->uuid,
    ]);
    $service->handleInvoiceDeleting($open);

    expect($open->fresh()->status)->toBe('void')
        ->and(Transaction::query()->findOrFail('transaction-delete')->status)->toBe(Transaction::STATUS_VOIDED)
        ->and($ledger->calls)->toHaveCount(1)
        ->and($ledger->calls[0]['description'])->toContain('INVOICE-DELETE');

    $paid = insertRevenueInvoice('invoice-paid-delete', 'paid', 'order-delete', amountPaid: 600);
    $service->handleInvoiceDeleting($paid);
    expect($paid->fresh()->meta['revenue_lifecycle_requires_review'])->toBeTrue()
        ->and($paid->fresh()->meta['revenue_lifecycle_review_reason'])->toBe('invoice_deleted');
});

test('invoice cancellation and restoration preserve previous status and source transaction', function () {
    $ledger  = new RevenueLifecycleLedgerSpy();
    $service = new RevenueLifecycleService($ledger);
    $invoice = insertRevenueInvoice('invoice-cancel', 'cancelled', 'order-cancel', 'transaction-cancel');
    insertRevenueTransaction('transaction-cancel');

    $service->handleInvoiceCanceled($invoice, 'sent', 'manual_cancel');
    $invoice->refresh();
    expect($invoice->meta['revenue_lifecycle_previous_status'])->toBe('sent')
        ->and(Transaction::query()->findOrFail('transaction-cancel')->status)->toBe(Transaction::STATUS_VOIDED);

    $service->handleInvoiceRestored($invoice);
    expect($invoice->fresh()->status)->toBe('sent')
        ->and($invoice->fresh()->meta['revenue_lifecycle_status_changed'])->toBeFalse()
        ->and(Transaction::query()->findOrFail('transaction-cancel')->status)->toBe('success');

    $paid = insertRevenueInvoice('invoice-part-paid', 'cancelled', 'order-cancel', amountPaid: 1);
    $service->handleInvoiceCanceled($paid, 'sent', 'paid_cancel');
    expect($paid->fresh()->meta['revenue_lifecycle_requires_review'])->toBeTrue();
});

test('delete wrappers and repair operations only act on terminal lifecycle states', function () {
    $ledger  = new RevenueLifecycleLedgerSpy();
    $service = new RevenueLifecycleService($ledger);

    $activeOrder = new RevenueLifecycleOrder('order-active', 'order_active', status: 'active');
    $service->repairOrder($activeOrder);
    expect(LoggerManager::$records)->toBeEmpty();

    $deletedOrder = new RevenueLifecycleOrder('order-deleted', 'order_deleted', status: 'active', deleted_at: now());
    $service->handleOrderDeleted($deletedOrder);
    $service->handleOrderRestoredFromDelete($deletedOrder);
    $service->repairOrder($deletedOrder, 'repair_deleted');

    $cancelledOrder = new RevenueLifecycleOrder('order-cancelled', 'order_cancelled', status: 'canceled');
    $service->repairOrder($cancelledOrder, 'repair_cancelled');

    $activeInvoice = insertRevenueInvoice('invoice-active-repair', 'sent', 'order-repair');
    $service->repairInvoice($activeInvoice);
    expect($activeInvoice->fresh()->status)->toBe('sent');

    $deletedInvoice = insertRevenueInvoice('invoice-deleted-repair', 'draft', 'order-repair', deletedAt: now());
    $service->repairInvoice($deletedInvoice, 'repair_invoice');
    expect($deletedInvoice->fresh()->status)->toBe('void');

    $deletedPaidInvoice = insertRevenueInvoice('invoice-paid-repair', 'paid', 'order-repair', amountPaid: 1000, deletedAt: now());
    $service->repairInvoice($deletedPaidInvoice, 'repair_paid_invoice');
    expect($deletedPaidInvoice->fresh()->meta['revenue_lifecycle_requires_review'])->toBeTrue();
});

test('invalid and already-corrected journals are skipped safely', function () {
    $cash    = revenueAccount('cash', Account::TYPE_ASSET);
    $revenue = revenueAccount('revenue', Account::TYPE_REVENUE);
    $order   = new RevenueLifecycleOrder('order-skip', 'order_skip');
    $ledger  = new RevenueLifecycleLedgerSpy();
    $service = new RevenueLifecycleService($ledger);

    revenueJournal('zero-journal', $cash, $revenue, 'storefront_sale', 0, ['order_uuid' => $order->uuid]);
    $missingAccount = revenueJournal('missing-account-journal', $cash, $revenue, 'storefront_sale', 100, ['order_uuid' => $order->uuid]);
    Capsule::table('ledger_journals')->where('uuid', $missingAccount->uuid)->update(['debit_account_uuid' => 'missing']);
    $alreadyReversedOriginal = revenueJournal('already-reversed-original', $cash, $revenue, 'storefront_sale', 100, ['order_uuid' => $order->uuid]);
    $alreadyReversed         = revenueJournal('already-reversed', $revenue, $cash, 'storefront_sale_reversal', 100, [
        'order_uuid'            => $order->uuid,
        'reverses_journal_uuid' => $alreadyReversedOriginal->uuid,
    ]);

    $service->handleOrderCanceled($order, 'active', 'cancelled');
    expect($ledger->calls)->toBeEmpty();

    revenueJournal('invalid-reinstatement-source', $revenue, $cash, 'storefront_sale_reversal', 0, [
        'order_uuid'            => $order->uuid,
        'reverses_journal_uuid' => 'invalid-original',
    ]);
    revenueJournal('already-reinstated', $cash, $revenue, 'storefront_sale_reinstatement', 100, [
        'order_uuid'              => $order->uuid,
        'reinstates_journal_uuid' => $alreadyReversed->uuid,
    ]);
    insertRevenueInvoice('manual-cancelled', 'cancelled', $order->uuid);
    $service->handleOrderRestored($order, 'cancelled', 'active');
    expect($ledger->calls)->toBeEmpty();

    $invoice = insertRevenueInvoice('invoice-journal-skip', 'draft', 'order-invoice-skip');
    revenueJournal('invoice-zero', $cash, $revenue, 'revenue_recognition', 0, ['invoice_uuid' => $invoice->uuid]);
    $invoiceMissing = revenueJournal('invoice-missing-account', $cash, $revenue, 'revenue_recognition', 100, ['invoice_uuid' => $invoice->uuid]);
    Capsule::table('ledger_journals')->where('uuid', $invoiceMissing->uuid)->update(['credit_account_uuid' => 'missing']);
    $invoiceOriginal = revenueJournal('invoice-already-reversed-original', $cash, $revenue, 'revenue_recognition', 100, ['invoice_uuid' => $invoice->uuid]);
    revenueJournal('invoice-already-reversed', $revenue, $cash, 'revenue_recognition_reversal', 100, [
        'invoice_uuid'          => $invoice->uuid,
        'reverses_journal_uuid' => $invoiceOriginal->uuid,
    ]);
    $service->handleInvoiceDeleting($invoice);
    expect($ledger->calls)->toBeEmpty();
});

test('invoice transaction restoration guards absent and unrelated transactions', function () {
    $service            = new RevenueLifecycleService(new RevenueLifecycleLedgerSpy());
    $withoutTransaction = insertRevenueInvoice('restore-no-transaction', 'cancelled', 'order-restore-guard');
    $service->handleInvoiceRestored($withoutTransaction);

    $missingTransaction = insertRevenueInvoice(
        'restore-missing-transaction',
        'cancelled',
        'order-restore-guard',
        'transaction-does-not-exist'
    );
    $service->handleInvoiceRestored($missingTransaction);

    insertRevenueTransaction('transaction-not-lifecycle-voided', Transaction::STATUS_VOIDED, [], now());
    $unrelatedTransaction = insertRevenueInvoice(
        'restore-unrelated-transaction',
        'cancelled',
        'order-restore-guard',
        'transaction-not-lifecycle-voided'
    );
    $service->handleInvoiceRestored($unrelatedTransaction);

    expect(Transaction::query()->findOrFail('transaction-not-lifecycle-voided')->status)
        ->toBe(Transaction::STATUS_VOIDED);
});

test('paid order invoices prevent order transaction voiding', function () {
    $service = new RevenueLifecycleService(new RevenueLifecycleLedgerSpy());
    $order   = new RevenueLifecycleOrder('order-paid', 'order_paid', 'order-paid-transaction');
    insertRevenueTransaction('order-paid-transaction');
    insertRevenueInvoice('invoice-order-paid', 'paid', $order->uuid, amountPaid: 1000);

    $service->handleOrderCanceled($order, 'complete', 'cancelled');

    expect(Transaction::query()->findOrFail('order-paid-transaction')->status)->toBe('success')
        ->and(Invoice::query()->without(['customer', 'items', 'template', 'order'])->findOrFail('invoice-order-paid')->meta['revenue_lifecycle_requires_review'])->toBeTrue();
});

test('lifecycle database failures are contained and logged for observer safety', function () {
    $service = new RevenueLifecycleService(new RevenueLifecycleLedgerSpy());
    $order   = new RevenueLifecycleOrder('order-error', 'order_error');
    Capsule::schema('testing')->drop('ledger_journals');

    $service->handleOrderCanceled($order, 'active', 'cancelled');
    $service->handleOrderRestored($order, 'cancelled', 'active');

    $invoice = insertRevenueInvoice('invoice-error', 'draft', 'order-error');
    Capsule::schema('testing')->drop('ledger_invoices');
    $service->handleInvoiceDeleting($invoice);
    $service->handleInvoiceCanceled($invoice, 'draft');
    $service->handleInvoiceRestored($invoice);

    expect(collect(LoggerManager::$records)->where('level', 'error'))->toHaveCount(5);
});
