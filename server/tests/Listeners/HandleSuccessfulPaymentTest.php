<?php

use Fleetbase\Ledger\DTO\GatewayResponse;
use Fleetbase\Ledger\Events\PaymentSucceeded;
use Fleetbase\Ledger\Listeners\HandleSuccessfulPayment;
use Fleetbase\Ledger\Models\Account;
use Fleetbase\Ledger\Models\Gateway;
use Fleetbase\Ledger\Models\GatewayTransaction;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Models\Journal;
use Fleetbase\Ledger\Services\InvoiceService;
use Fleetbase\Ledger\Services\LedgerService;
use Fleetbase\TestSupport\LoggerManager;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

class SuccessfulPaymentLedgerSpy extends LedgerService
{
    public array $calls          = [];
    public ?Throwable $exception = null;

    public function createJournalEntry(
        Account $debitAccount,
        Account $creditAccount,
        int $amount,
        string $description = '',
        array $options = [],
    ): Journal {
        if ($this->exception) {
            throw $this->exception;
        }

        $this->calls[] = compact('debitAccount', 'creditAccount', 'amount', 'description', 'options');

        return new Journal();
    }
}

class SuccessfulPaymentInvoiceSpy extends InvoiceService
{
    public array $calls          = [];
    public ?Throwable $exception = null;

    public function __construct()
    {
    }

    public function recordPayment(Invoice $invoice, int $amount, array $options = []): Invoice
    {
        if ($this->exception) {
            throw $this->exception;
        }

        $this->calls[] = compact('invoice', 'amount', 'options');

        return $invoice;
    }
}

function bootSuccessfulPaymentDatabase(): void
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
        $table->string('_key')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('created_by_uuid')->nullable();
        $table->string('updated_by_uuid')->nullable();
        $table->string('status');
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('ledger_gateway_transactions', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('company_uuid')->nullable();
        $table->string('gateway_uuid')->nullable();
        $table->string('gateway_reference_id')->nullable();
        $table->string('type');
        $table->string('event_type')->nullable();
        $table->unsignedBigInteger('amount')->nullable();
        $table->string('currency')->nullable();
        $table->string('status')->default('pending');
        $table->text('message')->nullable();
        $table->text('raw_response')->nullable();
        $table->timestamp('processed_at')->nullable();
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
}

function insertSuccessfulPaymentInvoice(
    string $uuid = 'invoice-success',
    string $publicId = 'invoice_public_success',
    string $status = 'pending',
): void {
    Capsule::table('ledger_invoices')->insert([
        'uuid'         => $uuid,
        'public_id'    => $publicId,
        'company_uuid' => 'company-success',
        'status'       => $status,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
}

function createSuccessfulPaymentAudit(array $attributes = []): GatewayTransaction
{
    static $sequence = 0;
    $sequence++;

    return GatewayTransaction::create(array_merge([
        'uuid'                 => sprintf('20000000-0000-4000-8000-%012d', $sequence),
        'public_id'            => 'gtxn_success_' . $sequence,
        'company_uuid'         => 'company-success',
        'gateway_uuid'         => 'gateway-success',
        'gateway_reference_id' => 'provider-success-' . $sequence,
        'type'                 => 'webhook_event',
        'event_type'           => GatewayResponse::EVENT_PAYMENT_SUCCEEDED,
        'amount'               => 2500,
        'currency'             => 'USD',
        'status'               => GatewayResponse::STATUS_SUCCEEDED,
        'raw_response'         => [],
    ], $attributes));
}

function successfulPaymentEvent(
    GatewayTransaction $transaction,
    int $amount = 2500,
    ?string $currency = 'USD',
    array $responseData = [],
): PaymentSucceeded {
    $response = GatewayResponse::success(
        gatewayTransactionId: $transaction->gateway_reference_id ?? 'provider-success',
        eventType: GatewayResponse::EVENT_PAYMENT_SUCCEEDED,
        amount: $amount,
        currency: $currency,
        data: $responseData,
    );
    $gateway = new Gateway([
        'uuid'         => 'gateway-success',
        'company_uuid' => 'company-success',
        'driver'       => 'test-provider',
        'name'         => 'Test Provider',
    ]);

    return new PaymentSucceeded($response, $gateway, $transaction);
}

beforeEach(function () {
    bootSuccessfulPaymentDatabase();
    LoggerManager::$records = [];
});

test('invoice-linked payments delegate accounting and seal the gateway transaction', function () {
    insertSuccessfulPaymentInvoice();
    $transaction = createSuccessfulPaymentAudit([
        'raw_response' => ['invoice_uuid' => 'invoice-success'],
    ]);
    $ledgerService  = new SuccessfulPaymentLedgerSpy();
    $invoiceService = new SuccessfulPaymentInvoiceSpy();

    (new HandleSuccessfulPayment($ledgerService, $invoiceService))
        ->handle(successfulPaymentEvent($transaction));

    expect($transaction->fresh()->isProcessed())->toBeTrue()
        ->and($ledgerService->calls)->toBeEmpty()
        ->and($invoiceService->calls)->toHaveCount(1)
        ->and($invoiceService->calls[0]['invoice']->uuid)->toBe('invoice-success')
        ->and($invoiceService->calls[0]['amount'])->toBe(2500)
        ->and($invoiceService->calls[0]['options']['reference'])->toBe($transaction->gateway_reference_id)
        ->and($invoiceService->calls[0]['options']['gateway_transaction_uuid'])->toBe($transaction->uuid)
        ->and($invoiceService->calls[0]['options']['currency'])->toBe('USD')
        ->and(LoggerManager::$records[array_key_last(LoggerManager::$records)]['message'])
        ->toBe('HandleSuccessfulPayment: completed.');
});

test('already-paid invoices and zero-value events do not duplicate accounting', function () {
    insertSuccessfulPaymentInvoice(status: 'paid');
    $ledgerService  = new SuccessfulPaymentLedgerSpy();
    $invoiceService = new SuccessfulPaymentInvoiceSpy();

    $paid = createSuccessfulPaymentAudit([
        'raw_response' => ['metadata' => ['invoice_uuid' => 'invoice-success']],
    ]);
    (new HandleSuccessfulPayment($ledgerService, $invoiceService))
        ->handle(successfulPaymentEvent($paid));

    $zero = createSuccessfulPaymentAudit(['gateway_reference_id' => null]);
    (new HandleSuccessfulPayment($ledgerService, $invoiceService))
        ->handle(successfulPaymentEvent($zero, 0));

    expect($paid->fresh()->isProcessed())->toBeTrue()
        ->and($zero->fresh()->isProcessed())->toBeTrue()
        ->and($ledgerService->calls)->toBeEmpty()
        ->and($invoiceService->calls)->toBeEmpty()
        ->and(Account::query()->count())->toBe(0);
});

test('standalone payments create the default cash and revenue accounting contract', function () {
    $transaction    = createSuccessfulPaymentAudit();
    $ledgerService  = new SuccessfulPaymentLedgerSpy();
    $invoiceService = new SuccessfulPaymentInvoiceSpy();

    (new HandleSuccessfulPayment($ledgerService, $invoiceService))
        ->handle(successfulPaymentEvent($transaction, currency: null));

    expect(Account::query()->pluck('code')->sort()->values()->all())
        ->toBe(['CASH-DEFAULT', 'REV-DEFAULT'])
        ->and($invoiceService->calls)->toBeEmpty()
        ->and($ledgerService->calls)->toHaveCount(1)
        ->and($ledgerService->calls[0]['debitAccount']->code)->toBe('CASH-DEFAULT')
        ->and($ledgerService->calls[0]['creditAccount']->code)->toBe('REV-DEFAULT')
        ->and($ledgerService->calls[0]['amount'])->toBe(2500)
        ->and($ledgerService->calls[0]['description'])->toContain('Test Provider')
        ->and($ledgerService->calls[0]['options']['currency'])->toBe('USD')
        ->and($ledgerService->calls[0]['options']['journal_type'])->toBe('gateway_payment')
        ->and($ledgerService->calls[0]['options']['gateway_transaction_uuid'])->toBe($transaction->uuid)
        ->and($ledgerService->calls[0]['options']['meta']['gateway_driver'])->toBe('test-provider')
        ->and($transaction->fresh()->isProcessed())->toBeTrue();
});

test('all supported invoice-reference shapes resolve to the same invoice', function () {
    insertSuccessfulPaymentInvoice();
    $ledgerService  = new SuccessfulPaymentLedgerSpy();
    $invoiceService = new SuccessfulPaymentInvoiceSpy();
    $listener       = new HandleSuccessfulPayment($ledgerService, $invoiceService);

    $nested = createSuccessfulPaymentAudit([
        'raw_response' => ['data' => ['object' => ['metadata' => ['invoice_uuid' => 'invoice-success']]]],
    ]);
    $listener->handle(successfulPaymentEvent($nested));

    $normalized = createSuccessfulPaymentAudit();
    $listener->handle(successfulPaymentEvent($normalized, responseData: ['invoice_uuid' => 'invoice-success']));

    $purchaseTop = createSuccessfulPaymentAudit([
        'gateway_reference_id' => 'purchase-fallback-top',
        'type'                 => 'purchase',
        'raw_response'         => ['invoice_uuid' => 'invoice-success'],
    ]);
    $webhookTop = createSuccessfulPaymentAudit([
        'gateway_reference_id' => 'purchase-fallback-top',
    ]);
    $listener->handle(successfulPaymentEvent($webhookTop));

    $purchaseNested = createSuccessfulPaymentAudit([
        'gateway_reference_id' => 'purchase-fallback-nested',
        'type'                 => 'purchase',
        'raw_response'         => ['data' => ['invoice_uuid' => 'invoice-success']],
    ]);
    $webhookNested = createSuccessfulPaymentAudit([
        'gateway_reference_id' => 'purchase-fallback-nested',
    ]);
    $listener->handle(successfulPaymentEvent($webhookNested));

    $publicId = createSuccessfulPaymentAudit([
        'raw_response' => ['invoice_uuid' => 'invoice_public_success'],
    ]);
    $listener->handle(successfulPaymentEvent($publicId));

    expect($nested->fresh()->isProcessed())->toBeTrue()
        ->and($normalized->fresh()->isProcessed())->toBeTrue()
        ->and($webhookTop->fresh()->isProcessed())->toBeTrue()
        ->and($webhookNested->fresh()->isProcessed())->toBeTrue()
        ->and($publicId->fresh()->isProcessed())->toBeTrue()
        ->and($purchaseTop->isProcessed())->toBeFalse()
        ->and($purchaseNested->isProcessed())->toBeFalse()
        ->and($ledgerService->calls)->toBeEmpty()
        ->and($invoiceService->calls)->toHaveCount(5);
});

test('processed events are skipped before downstream services are called', function () {
    $transaction  = createSuccessfulPaymentAudit(['processed_at' => now()]);
    $ledger       = new SuccessfulPaymentLedgerSpy();
    $invoices     = new SuccessfulPaymentInvoiceSpy();

    (new HandleSuccessfulPayment($ledger, $invoices))
        ->handle(successfulPaymentEvent($transaction));

    expect(LoggerManager::$records[array_key_last(LoggerManager::$records)]['message'])
        ->toBe('HandleSuccessfulPayment: already processed, skipping.')
        ->and($ledger->calls)->toBeEmpty()
        ->and($invoices->calls)->toBeEmpty();
});

test('downstream accounting failures are logged and rethrown for queue retry', function () {
    insertSuccessfulPaymentInvoice();
    $transaction = createSuccessfulPaymentAudit([
        'raw_response' => ['invoice_uuid' => 'invoice-success'],
    ]);
    $ledger              = new SuccessfulPaymentLedgerSpy();
    $invoices            = new SuccessfulPaymentInvoiceSpy();
    $invoices->exception = new RuntimeException('Accounting unavailable.');

    expect(fn () => (new HandleSuccessfulPayment($ledger, $invoices))
        ->handle(successfulPaymentEvent($transaction)))
        ->toThrow(RuntimeException::class, 'Accounting unavailable.');

    $error = LoggerManager::$records[array_key_last(LoggerManager::$records)];
    expect($error['level'])->toBe('error')
        ->and($error['message'])->toBe('HandleSuccessfulPayment: failed.')
        ->and($error['context']['gateway_transaction_uuid'])->toBe($transaction->uuid)
        ->and($transaction->fresh()->isProcessed())->toBeFalse();
});
