<?php

use Fleetbase\Ledger\DTO\GatewayResponse;
use Fleetbase\Ledger\Events\PaymentFailed;
use Fleetbase\Ledger\Listeners\HandleFailedPayment;
use Fleetbase\Ledger\Models\Gateway;
use Fleetbase\Ledger\Models\GatewayTransaction;
use Fleetbase\TestSupport\LoggerManager;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

function bootFailedPaymentDatabase(): void
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
}

function failedPaymentEvent(
    GatewayTransaction $transaction,
    string $invoiceUuid = 'invoice-pending',
): PaymentFailed {
    $response = GatewayResponse::failure(
        gatewayTransactionId: 'failed-provider-payment',
        eventType: GatewayResponse::EVENT_PAYMENT_FAILED,
        message: 'Card declined.',
        errorCode: 'card_declined',
        rawResponse: ['metadata' => ['invoice_uuid' => $invoiceUuid]],
    );
    $gateway = new Gateway([
        'uuid'         => 'gateway-failed-payment',
        'company_uuid' => 'company-test',
        'driver'       => 'test-provider',
        'name'         => 'Test Provider',
    ]);

    return new PaymentFailed($response, $gateway, $transaction);
}

beforeEach(function () {
    bootFailedPaymentDatabase();
    LoggerManager::$records = [];
});

test('failed payments make pending invoices overdue and seal the audit record', function () {
    Capsule::table('ledger_invoices')->insert([
        'uuid'       => 'invoice-pending',
        'public_id'  => 'invoice_public_pending',
        'status'     => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $transaction = GatewayTransaction::create([
        'uuid'                 => 'gateway-transaction-failed',
        'public_id'            => 'gtxn_failed',
        'company_uuid'         => 'company-test',
        'gateway_uuid'         => 'gateway-failed-payment',
        'gateway_reference_id' => 'failed-provider-payment',
        'type'                 => 'purchase',
        'event_type'           => GatewayResponse::EVENT_PAYMENT_FAILED,
        'status'               => GatewayResponse::STATUS_FAILED,
    ]);

    (new HandleFailedPayment())->handle(failedPaymentEvent($transaction));
    $transaction->refresh();

    expect(Capsule::table('ledger_invoices')->value('status'))->toBe('overdue')
        ->and($transaction->isProcessed())->toBeTrue()
        ->and(LoggerManager::$records[array_key_last(LoggerManager::$records)]['level'])->toBe('warning')
        ->and(LoggerManager::$records[array_key_last(LoggerManager::$records)]['context']['error_code'])->toBe('card_declined');
});

test('late failures cannot regress paid invoices and processed events are idempotent', function () {
    Capsule::table('ledger_invoices')->insert([
        'uuid'       => 'invoice-paid',
        'public_id'  => 'invoice_public_paid',
        'status'     => 'paid',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $transaction = new GatewayTransaction([
        'uuid'         => 'already-processed',
        'processed_at' => now(),
    ]);

    (new HandleFailedPayment())->handle(failedPaymentEvent($transaction, 'invoice-paid'));

    expect(Capsule::table('ledger_invoices')->value('status'))->toBe('paid')
        ->and(LoggerManager::$records)->toBeEmpty();

    $transaction->processed_at = null;
    $transaction->exists       = false;
    (new HandleFailedPayment())->handle(failedPaymentEvent($transaction, 'invoice-paid'));

    expect(Capsule::table('ledger_invoices')->value('status'))->toBe('paid');
});

test('listener failures are logged and rethrown for queue retry', function () {
    $transaction = new GatewayTransaction([
        'uuid'         => 'failed-listener-transaction',
        'processed_at' => null,
    ]);
    Capsule::schema('testing')->drop('ledger_invoices');

    expect(fn () => (new HandleFailedPayment())->handle(failedPaymentEvent($transaction)))
        ->toThrow(QueryException::class);

    $error = LoggerManager::$records[array_key_last(LoggerManager::$records)];
    expect($error['level'])->toBe('error')
        ->and($error['message'])->toBe('HandleFailedPayment: failed.')
        ->and($error['context']['error'])->toContain('ledger_invoices');
});
