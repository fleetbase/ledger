<?php

use Fleetbase\Ledger\Models\Account;
use Fleetbase\Ledger\Models\Gateway;
use Fleetbase\Ledger\Models\GatewayTransaction;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Models\Journal;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;

beforeEach(function () {
    $capsule    = new Capsule(Container::getInstance());
    $dispatcher = new Dispatcher(Container::getInstance());
    $database   = tempnam(sys_get_temp_dir(), 'ledger-model-lifecycle-');
    $capsule->setEventDispatcher($dispatcher);
    Model::setEventDispatcher($dispatcher);
    Model::clearBootedModels();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => $database, 'prefix' => ''], 'testing');
    $capsule->addConnection(['driver' => 'sqlite', 'database' => $database, 'prefix' => ''], 'mysql');
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('testing');
    Container::getInstance()->instance('db', $capsule->getDatabaseManager());
    Container::getInstance()->instance('cache', new Repository(new ArrayStore()));
    Facade::clearResolvedInstance('db');
    Facade::clearResolvedInstance('cache');

    $schema = Capsule::schema('testing');
    $schema->create('settings', function (Blueprint $table) {
        $table->increments('id');
        $table->string('key')->unique();
        $table->text('value')->nullable();
    });
    $schema->create('ledger_invoices', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('_key')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('number')->nullable()->unique();
        $table->string('currency')->nullable();
        $table->date('date')->nullable();
        $table->date('due_date')->nullable();
        $table->string('notes')->nullable();
        $table->string('terms')->nullable();
        $table->string('template_uuid')->nullable();
        $table->string('status')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('ledger_journals', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('_key')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('debit_account_uuid')->nullable();
        $table->string('credit_account_uuid')->nullable();
        $table->string('number')->nullable();
        $table->string('status')->nullable();
        $table->string('type')->nullable();
        $table->integer('amount')->default(0);
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('ledger_invoice_items', function (Blueprint $table) {
        $table->increments('id');
        $table->string('invoice_uuid');
        $table->softDeletes();
    });
    $schema->create('templates', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->softDeletes();
    });
    $schema->create('ledger_gateways', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('_key')->nullable();
        $table->string('public_id')->nullable();
        $table->boolean('is_sandbox')->default(false);
        $table->string('environment')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('ledger_gateway_transactions', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('_key')->nullable();
        $table->string('public_id')->nullable();
        $table->string('gateway_reference_id')->nullable();
        $table->string('type')->nullable();
        $table->dateTime('processed_at')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
});

test('invoice creation normalizes malformed settings and applies complete configured defaults', function () {
    session(['company' => 'company-model-lifecycle']);
    Capsule::table('settings')->insert([
        'key'   => 'company.company-model-lifecycle.ledger.invoice-settings',
        'value' => json_encode('malformed'),
    ]);

    $sparse = new Invoice([
        'uuid'      => 'invoice-sparse',
        'public_id' => 'invoice_sparse',
        'number'    => 'INV-SPARSE',
    ]);
    $sparse->save();
    expect($sparse->number)->toBe('INV-SPARSE');

    Capsule::table('settings')->where('key', 'company.company-model-lifecycle.ledger.invoice-settings')->update([
        'value' => json_encode([
            'invoice_prefix'        => 'BILL',
            'payment_terms_days'    => 14,
            'default_currency'      => 'MNT',
            'default_notes'         => 'Thank you',
            'default_terms'         => 'Net 14',
            'default_template_uuid' => 'template-1',
        ]),
    ]);
    Cache::flush();
    $invoice = new Invoice([
        'uuid'      => 'invoice-defaulted',
        'public_id' => 'invoice_defaulted',
        'date'      => '2026-07-01',
    ]);
    $invoice->save();

    expect($invoice->number)->toStartWith('BILL-')
        ->and($invoice->currency)->toBe('MNT')
        ->and($invoice->due_date->format('Y-m-d'))->toBe('2026-07-15')
        ->and($invoice->notes)->toBe('Thank you')
        ->and($invoice->terms)->toBe('Net 14')
        ->and($invoice->template_uuid)->toBe('template-1');

    Capsule::table('settings')->where('key', 'company.company-model-lifecycle.ledger.invoice-settings')->update([
        'value' => json_encode(['due_date_offset_days' => 5]),
    ]);
    Cache::flush();
    $legacy = new Invoice([
        'uuid'      => 'invoice-legacy-terms',
        'public_id' => 'invoice_legacy_terms',
        'number'    => 'INV-LEGACY',
        'date'      => '2026-07-10',
    ]);
    $legacy->save();
    expect($legacy->due_date->format('Y-m-d'))->toBe('2026-07-15');
});

test('invoice creation applies the invoice company settings without a company session', function () {
    Capsule::table('settings')->insert([
        'key'   => 'company.company-queued-invoice.ledger.invoice-settings',
        'value' => json_encode(['invoice_prefix' => 'QUE', 'default_currency' => 'SGD']),
    ]);
    Cache::flush();

    // Invoices created from queued jobs and listeners have no company session
    session(['company' => null]);
    $invoice = new Invoice([
        'uuid'         => 'invoice-queued',
        'public_id'    => 'invoice_queued',
        'company_uuid' => 'company-queued-invoice',
        'date'         => '2026-07-01',
    ]);
    $invoice->save();

    expect($invoice->number)->toStartWith('QUE-')
        ->and($invoice->currency)->toBe('SGD');
});

test('invoice settings use the invoice company even when another company is signed in', function () {
    Capsule::table('settings')->insert([
        ['key' => 'company.company-invoice.ledger.invoice-settings', 'value' => json_encode(['invoice_prefix' => 'OWN', 'default_currency' => 'SGD'])],
        ['key' => 'company.company-session.ledger.invoice-settings', 'value' => json_encode(['invoice_prefix' => 'OTHER', 'default_currency' => 'USD'])],
    ]);
    Cache::flush();
    session(['company' => 'company-session']);
    $invoice = new Invoice([
        'uuid'         => 'invoice-explicit-company',
        'public_id'    => 'invoice_explicit_company',
        'company_uuid' => 'company-invoice',
        'date'         => '2026-07-01',
    ]);
    $invoice->save();

    expect($invoice->number)->toStartWith('OWN-')
        ->and($invoice->currency)->toBe('SGD');
});

test('invoice creation without any company context uses safe defaults', function () {
    session(['company' => null]);
    $invoice = new Invoice([
        'uuid'      => 'invoice-no-company',
        'public_id' => 'invoice_no_company',
        'date'      => '2026-07-01',
    ]);
    $invoice->save();

    expect($invoice->number)->toStartWith('INV-')
        ->and($invoice->currency)->toBeNull();
});

test('invoice number generation retries collisions including soft deleted records', function () {
    mt_srand(1234);
    $first  = mt_rand(1, 9);
    $second = mt_rand(1, 9);
    expect($second)->not->toBe($first);

    Capsule::table('ledger_invoices')->insert([
        'uuid'       => 'existing-invoice',
        'number'     => 'SEQ-' . $first,
        'deleted_at' => now(),
    ]);
    mt_srand(1234);

    expect(Invoice::generateNumber('SEQ', 1))->toBe('SEQ-' . $second);
});

test('journal and gateway model hooks apply deterministic persisted defaults', function () {
    $journal = new Journal([
        'uuid'         => 'journal-1',
        'public_id'    => 'journal_public_1',
        'company_uuid' => 'company-1',
        'amount'       => 100,
    ]);
    $journal->save();

    expect($journal->number)->toBe('JE-00001')
        ->and($journal->status)->toBe('posted')
        ->and($journal->type)->toBe('general');

    $gateway = new Gateway([
        'uuid'       => 'gateway-1',
        'public_id'  => 'gateway_public_1',
        'is_sandbox' => true,
    ]);
    $gateway->save();
    expect($gateway->environment)->toBe('sandbox');
});

test('account journal query and gateway transaction idempotency use persisted contracts', function () {
    Capsule::table('ledger_journals')->insert([
        ['uuid' => 'debit-journal', 'debit_account_uuid' => 'account-1', 'amount' => 100],
        ['uuid' => 'credit-journal', 'credit_account_uuid' => 'account-1', 'amount' => 50],
        ['uuid' => 'other-journal', 'debit_account_uuid' => 'account-2', 'amount' => 25],
    ]);
    $account = new Account();
    $account->setAttribute('uuid', 'account-1');

    expect($account->journals()->count())->toBe(2);

    Capsule::table('ledger_gateway_transactions')->insert([
        'uuid'                 => 'processed-transaction',
        'gateway_reference_id' => 'provider-ref',
        'type'                 => 'webhook_event',
        'processed_at'         => now(),
    ]);

    expect(GatewayTransaction::alreadyProcessed('provider-ref'))->toBeTrue()
        ->and(GatewayTransaction::alreadyProcessed('provider-ref', 'refund'))->toBeFalse()
        ->and(GatewayTransaction::alreadyProcessed('missing-ref'))->toBeFalse();
});
