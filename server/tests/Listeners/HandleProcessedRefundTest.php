<?php

use Fleetbase\Ledger\DTO\GatewayResponse;
use Fleetbase\Ledger\Events\RefundProcessed;
use Fleetbase\Ledger\Listeners\HandleProcessedRefund;
use Fleetbase\Ledger\Models\Account;
use Fleetbase\Ledger\Models\Gateway;
use Fleetbase\Ledger\Models\GatewayTransaction;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Models\Journal;
use Fleetbase\Ledger\Models\Transaction;
use Fleetbase\Ledger\Services\LedgerService;
use Fleetbase\TestSupport\LoggerManager;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

class ProcessedRefundLedgerSpy extends LedgerService
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

        return new Journal();
    }
}

function bootProcessedRefundDatabase(): void
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
    $schema->create('ledger_invoices', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('company_uuid')->nullable();
        $table->string('customer_uuid')->nullable();
        $table->string('customer_type')->nullable();
        $table->string('number')->nullable();
        $table->bigInteger('total_amount')->default(0);
        $table->string('currency')->nullable();
        $table->string('status');
        $table->text('meta')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('ledger_gateway_transactions', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('company_uuid')->nullable();
        $table->string('gateway_uuid')->nullable();
        $table->string('transaction_uuid')->nullable();
        $table->string('gateway_reference_id')->nullable();
        $table->string('type');
        $table->string('event_type')->nullable();
        $table->unsignedBigInteger('amount')->nullable();
        $table->string('currency')->nullable();
        $table->string('status')->default('pending');
        $table->text('message')->nullable();
        $table->text('raw_response')->nullable();
        $table->timestamp('processed_at')->nullable();
        $table->string('refund_status')->nullable();
        $table->timestamp('refund_accepted_at')->nullable();
        $table->timestamp('refund_expires_at')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('ledger_invoice_items', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('invoice_uuid')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
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
    $schema->create('transactions', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('company_uuid')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('owner_type')->nullable();
        $table->string('customer_uuid')->nullable();
        $table->string('customer_type')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('payer_uuid')->nullable();
        $table->string('payer_type')->nullable();
        $table->string('payee_uuid')->nullable();
        $table->string('payee_type')->nullable();
        $table->string('context_uuid')->nullable();
        $table->string('context_type')->nullable();
        $table->bigInteger('amount');
        $table->bigInteger('net_amount')->nullable();
        $table->string('currency')->nullable();
        $table->string('description')->nullable();
        $table->string('type');
        $table->string('direction');
        $table->string('status');
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
}

function insertRefundInvoice(
    string $uuid,
    int $total,
    string $status = 'paid',
    array $meta = [],
): void {
    Capsule::table('ledger_invoices')->insert([
        'uuid'          => $uuid,
        'public_id'     => $uuid . '-public',
        'company_uuid'  => 'company-refund',
        'customer_uuid' => null,
        'customer_type' => null,
        'number'        => 'INV-' . $uuid,
        'total_amount'  => $total,
        'currency'      => 'USD',
        'status'        => $status,
        'meta'          => json_encode($meta),
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);
}

function createRefundAudit(array $attributes = []): GatewayTransaction
{
    static $sequence = 0;
    $sequence++;

    return GatewayTransaction::create(array_merge([
        'uuid'                 => sprintf('30000000-0000-4000-8000-%012d', $sequence),
        'public_id'            => 'gtxn_refund_' . $sequence,
        'company_uuid'         => 'company-refund',
        'gateway_uuid'         => 'gateway-refund',
        'gateway_reference_id' => 'refund-reference-' . $sequence,
        'type'                 => 'refund',
        'event_type'           => GatewayResponse::EVENT_REFUND_PROCESSED,
        'amount'               => 500,
        'currency'             => 'USD',
        'status'               => GatewayResponse::STATUS_SUCCEEDED,
        'raw_response'         => [],
    ], $attributes));
}

function processedRefundEvent(
    GatewayTransaction $transaction,
    int $amount,
    string $driver = 'cash',
    array $data = [],
    array $rawResponse = [],
    ?string $currency = 'USD',
): RefundProcessed {
    $response = GatewayResponse::success(
        gatewayTransactionId: $transaction->gateway_reference_id,
        eventType: GatewayResponse::EVENT_REFUND_PROCESSED,
        amount: $amount,
        currency: $currency,
        rawResponse: $rawResponse,
        data: $data,
    );
    $gateway = new Gateway([
        'uuid'         => 'gateway-refund',
        'company_uuid' => 'company-refund',
        'driver'       => $driver,
        'name'         => ucfirst($driver) . ' Gateway',
    ]);

    return new RefundProcessed($response, $gateway, $transaction);
}

beforeEach(function () {
    bootProcessedRefundDatabase();
    LoggerManager::$records = [];
});

test('partial refunds create a transaction, reversal journal, and invoice state', function () {
    insertRefundInvoice('invoice-partial', 1000);
    $audit  = createRefundAudit(['raw_response' => ['invoice_uuid' => 'invoice-partial']]);
    $ledger = new ProcessedRefundLedgerSpy();

    (new HandleProcessedRefund($ledger))->handle(processedRefundEvent(
        $audit,
        400,
        data: ['refund_kind' => 'partial', 'refund_status' => 'provider-approved']
    ));

    $invoice = Invoice::query()->without(['customer', 'items', 'template', 'order'])->findOrFail('invoice-partial');
    $audit->refresh();
    $transaction = Transaction::query()->firstOrFail();

    expect($invoice->status)->toBe('partial')
        ->and($invoice->meta['refunded_amount'])->toBe(400)
        ->and($audit->transaction_uuid)->toBe($transaction->uuid)
        ->and($audit->refund_status)->toBe('provider-approved')
        ->and($audit->isProcessed())->toBeTrue()
        ->and($transaction->settlement_status)->toBe(Transaction::SETTLEMENT_STATUS_PARTIALLY_REFUNDED)
        ->and($ledger->calls)->toHaveCount(1)
        ->and($ledger->calls[0]['debitAccount']->code)->toBe('REFUNDS-DEFAULT')
        ->and($ledger->calls[0]['creditAccount']->code)->toBe('CASH-DEFAULT')
        ->and($ledger->calls[0]['options']['meta']['invoice_uuid'])->toBe('invoice-partial');
});

test('taler refunds track wallet pickup and all invoice refund statuses', function () {
    $cases = [
        ['uuid' => 'cash-full', 'driver' => 'cash', 'amount' => 1000, 'total' => 1000, 'wallet' => null, 'status' => 'refunded'],
        ['uuid' => 'taler-partial', 'driver' => 'taler', 'amount' => 300, 'total' => 1000, 'wallet' => 'pending', 'status' => 'partial_refund_pending'],
        ['uuid' => 'taler-full', 'driver' => 'taler', 'amount' => 1000, 'total' => 1000, 'wallet' => 'pending', 'status' => 'refund_pending'],
        ['uuid' => 'taler-accepted', 'driver' => 'taler', 'amount' => 250, 'total' => 1000, 'wallet' => 'accepted', 'status' => 'partial'],
    ];
    $ledger = new ProcessedRefundLedgerSpy();

    foreach ($cases as $case) {
        insertRefundInvoice($case['uuid'], $case['total'], meta: ['pending_wallet_refund_amount' => 250]);
        $audit = createRefundAudit(['raw_response' => ['data' => ['invoice_uuid' => $case['uuid']]]]);
        (new HandleProcessedRefund($ledger))->handle(processedRefundEvent(
            $audit,
            $case['amount'],
            $case['driver'],
            [
                'refund_kind'      => $case['amount'] === $case['total'] ? 'full' : 'partial',
                'wallet_status'    => $case['wallet'],
                'taler_refund_uri' => 'taler://refund/' . $case['uuid'],
            ]
        ));

        $invoice = Invoice::query()->without(['customer', 'items', 'template', 'order'])->findOrFail($case['uuid']);
        $audit->refresh();
        expect($invoice->status)->toBe($case['status'])
            ->and($invoice->meta['last_taler_refund_uri'])->toBe('taler://refund/' . $case['uuid'])
            ->and($audit->refund_accepted_at !== null)->toBe($case['wallet'] === 'accepted');
    }
});

test('zero-value and already-processed refunds are safe idempotent no-ops', function () {
    $ledger = new ProcessedRefundLedgerSpy();
    $zero   = createRefundAudit();
    (new HandleProcessedRefund($ledger))->handle(processedRefundEvent($zero, 0, currency: null));
    $zero->refresh();

    expect($zero->isProcessed())->toBeTrue()
        ->and($zero->refund_status)->toBe(GatewayResponse::STATUS_SUCCEEDED)
        ->and($ledger->calls)->toBeEmpty()
        ->and(Transaction::query()->count())->toBe(0);

    LoggerManager::$records = [];
    (new HandleProcessedRefund($ledger))->handle(processedRefundEvent($zero, 100));
    expect(LoggerManager::$records)->toBeEmpty()
        ->and(Transaction::query()->count())->toBe(0);
});

test('response metadata paths and URL fallbacks resolve invoice refund data', function () {
    $ledger = new ProcessedRefundLedgerSpy();
    $cases  = [
        ['uuid' => 'from-data', 'data' => ['invoice_uuid' => 'from-data', 'refund_url' => 'https://refund/data'], 'raw' => []],
        ['uuid' => 'from-metadata', 'data' => [], 'raw' => ['metadata' => ['invoice_uuid' => 'from-metadata'], 'taler_refund_uri' => 'taler://raw']],
        ['uuid' => 'from-raw', 'data' => [], 'raw' => ['invoice_uuid' => 'from-raw', 'refund_url' => 'https://refund/raw']],
    ];

    foreach ($cases as $case) {
        insertRefundInvoice($case['uuid'], 1000);
        $audit = createRefundAudit();
        (new HandleProcessedRefund($ledger))->handle(processedRefundEvent(
            $audit,
            100,
            'cash',
            $case['data'],
            $case['raw']
        ));

        $invoice = Invoice::query()->without(['customer', 'items', 'template', 'order'])->findOrFail($case['uuid']);
        expect($invoice->meta['refunded_amount'])->toBe(100);
    }
});

test('refund accounting failures are logged and rethrown for queue retry', function () {
    $audit = createRefundAudit();
    Capsule::schema('testing')->drop('transactions');

    expect(fn () => (new HandleProcessedRefund(new ProcessedRefundLedgerSpy()))
        ->handle(processedRefundEvent($audit, 100)))
        ->toThrow(QueryException::class);

    $error = LoggerManager::$records[array_key_last(LoggerManager::$records)];
    expect($error['level'])->toBe('error')
        ->and($error['message'])->toBe('HandleProcessedRefund: failed.')
        ->and($error['context']['error'])->toContain('transactions');
});
