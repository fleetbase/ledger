<?php

use Fleetbase\Ledger\Http\Controllers\Internal\v1\SettingController;
use Fleetbase\Ledger\Models\Gateway;
use Fleetbase\Models\Setting;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

if (!class_exists(Fleetbase\Ledger\Models\InvoiceTemplate::class)) {
    eval(<<<'PHP'
namespace Fleetbase\Ledger\Models;

class InvoiceTemplate extends \Fleetbase\Models\Model
{
    protected $table = 'ledger_invoice_templates';
    protected $guarded = [];
    public $timestamps = false;
}
PHP);
}

class SettingControllerRequest extends Request
{
    public function validate(array $rules, ...$params)
    {
        return $this->all();
    }
}

class SettingControllerCache
{
    public function forget(string $key): bool
    {
        return true;
    }
}

if (!function_exists('cache')) {
    function cache(): SettingControllerCache
    {
        static $cache;

        return $cache ??= new SettingControllerCache();
    }
}

function settingControllerRequest(array $input = []): SettingControllerRequest
{
    return SettingControllerRequest::create('/ledger/settings', 'POST', $input);
}

function bootSettingControllerDatabase(): Capsule
{
    $capsule    = new Capsule(Container::getInstance());
    $dispatcher = new Dispatcher(Container::getInstance());
    $database   = tempnam(sys_get_temp_dir(), 'ledger-setting-controller-');
    $capsule->setEventDispatcher($dispatcher);
    Model::setEventDispatcher($dispatcher);
    Model::clearBootedModels();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => $database, 'prefix' => ''], 'testing');
    $capsule->addConnection(['driver' => 'sqlite', 'database' => $database, 'prefix' => ''], 'mysql');
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('testing');
    Container::getInstance()->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstance('db');
    session(['company' => 'company-setting-controller']);

    $schema = $capsule->getConnection('testing')->getSchemaBuilder();
    $schema->create('settings', function (Blueprint $table) {
        $table->increments('id');
        $table->string('key')->unique();
        $table->text('value')->nullable();
    });
    $schema->create('ledger_invoice_templates', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid');
        $table->string('name');
        $table->softDeletes();
    });
    $schema->create('ledger_gateways', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('_key')->nullable();
        $table->string('company_uuid');
        $table->string('name');
        $table->string('driver');
        $table->text('config')->nullable();
        $table->text('capabilities')->nullable();
        $table->text('meta')->nullable();
        $table->boolean('is_sandbox')->default(false);
        $table->string('environment')->nullable();
        $table->string('status');
        $table->string('return_url')->nullable();
        $table->string('webhook_url')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });

    return $capsule;
}

function settingControllerJson(mixed $response): array
{
    return json_decode($response->getContent(), true);
}

beforeEach(function () {
    bootSettingControllerDatabase();
});

test('invoice settings return defaults and resolve a company template', function () {
    $controller = new SettingController();

    $defaults = settingControllerJson($controller->getInvoiceSettings())['invoiceSettings'];
    expect($defaults)
        ->toMatchArray([
            'invoice_prefix'        => 'INV',
            'default_currency'      => 'USD',
            'payment_terms_days'    => 30,
            'due_date_offset_days'  => 30,
            'auto_send_on_creation' => false,
            'default_template'      => null,
        ]);

    Fleetbase\Ledger\Models\InvoiceTemplate::query()->create([
        'uuid'         => 'template-uuid',
        'public_id'    => 'template_public',
        'company_uuid' => 'company-setting-controller',
        'name'         => 'Standard invoice',
    ]);
    Setting::configureCompany('ledger.invoice-settings', [
        'invoice_prefix'        => 'BILL',
        'due_date_offset_days'  => '14',
        'default_template_uuid' => 'template_public',
    ]);

    $settings = settingControllerJson($controller->getInvoiceSettings())['invoiceSettings'];
    expect($settings['payment_terms_days'])->toBe(14)
        ->and($settings['due_date_offset_days'])->toBe(14)
        ->and($settings['default_template'])->toBe([
            'uuid'      => 'template-uuid',
            'public_id' => 'template_public',
            'name'      => 'Standard invoice',
        ]);
});

test('invoice settings save canonical terms, preserve existing keys, and normalize templates', function () {
    $controller = new SettingController();
    Fleetbase\Ledger\Models\InvoiceTemplate::query()->create([
        'uuid'         => 'template-uuid',
        'public_id'    => 'template_public',
        'company_uuid' => 'company-setting-controller',
        'name'         => 'Standard invoice',
    ]);
    Setting::configureCompany('ledger.invoice-settings', [
        'default_notes'      => 'Keep me',
        'payment_terms_days' => 60,
    ]);

    $payload = settingControllerJson($controller->saveInvoiceSettings(settingControllerRequest([
        'invoiceSettings' => [
            'invoice_prefix'        => 'BILL',
            'due_date_offset_days'  => '21',
            'default_template_uuid' => 'template_public',
        ],
    ])));

    expect($payload['status'])->toBe('ok')
        ->and($payload['invoiceSettings'])->toMatchArray([
            'invoice_prefix'        => 'BILL',
            'default_notes'         => 'Keep me',
            'payment_terms_days'    => 21,
            'due_date_offset_days'  => 21,
            'default_template_uuid' => 'template-uuid',
        ])
        ->and(Setting::lookupCompany('ledger.invoice-settings')['default_template_uuid'])->toBe('template-uuid');
});

test('invoice settings reject foreign templates and safely replace malformed stored values', function () {
    $controller = new SettingController();
    Fleetbase\Ledger\Models\InvoiceTemplate::query()->create([
        'uuid'         => 'foreign-template',
        'public_id'    => 'foreign_public',
        'company_uuid' => 'another-company',
        'name'         => 'Foreign',
    ]);

    $response = $controller->saveInvoiceSettings(settingControllerRequest([
        'invoiceSettings' => ['default_template_uuid' => 'foreign_public'],
    ]));
    expect($response->getStatusCode())->toBe(422)
        ->and(settingControllerJson($response)['error'])->toContain('not found');

    Capsule::table('settings')->insert([
        'key'   => 'company.company-setting-controller.ledger.invoice-settings',
        'value' => json_encode('malformed'),
    ]);
    expect(settingControllerJson($controller->getInvoiceSettings())['invoiceSettings'])
        ->toMatchArray(['payment_terms_days' => 30, 'due_date_offset_days' => 30]);

    $saved = settingControllerJson($controller->saveInvoiceSettings(settingControllerRequest([
        'invoiceSettings' => ['payment_terms_days' => null],
    ])))['invoiceSettings'];
    expect($saved['payment_terms_days'])->toBeNull()
        ->and($saved['due_date_offset_days'])->toBeNull();
});

test('payment settings return defaults and expose only the selected company gateway contract', function () {
    $controller = new SettingController();
    $gateway    = Gateway::withoutEvents(fn () => Gateway::query()->create([
        'uuid'         => 'gateway-uuid',
        'public_id'    => 'gateway_public',
        'company_uuid' => 'company-setting-controller',
        'name'         => 'Taler',
        'driver'       => 'taler',
        'environment'  => 'sandbox',
        'status'       => 'active',
    ]));

    expect(settingControllerJson($controller->getPaymentSettings())['paymentSettings'])
        ->toMatchArray([
            'default_gateway_uuid'   => null,
            'allow_partial_payments' => false,
            'send_payment_receipt'   => true,
            'default_gateway'        => null,
        ]);

    Setting::configureCompany('ledger.payment-settings', [
        'default_gateway_uuid'   => $gateway->public_id,
        'allow_partial_payments' => true,
    ]);
    $settings = settingControllerJson($controller->getPaymentSettings())['paymentSettings'];
    expect($settings['default_gateway'])->toBe([
        'uuid'        => 'gateway-uuid',
        'public_id'   => 'gateway_public',
        'name'        => 'Taler',
        'driver'      => 'taler',
        'environment' => 'sandbox',
        'status'      => 'active',
    ])->and($settings['allow_partial_payments'])->toBeTrue();
});

test('payment settings validate company ownership, normalize identifiers, and preserve keys', function () {
    $controller = new SettingController();
    Gateway::withoutEvents(fn () => Gateway::query()->create([
        'uuid'         => 'gateway-uuid',
        'public_id'    => 'gateway_public',
        'company_uuid' => 'company-setting-controller',
        'name'         => 'Cash',
        'driver'       => 'cash',
        'environment'  => 'live',
        'status'       => 'active',
    ]));
    Gateway::withoutEvents(fn () => Gateway::query()->create([
        'uuid'         => 'foreign-gateway',
        'public_id'    => 'foreign_public',
        'company_uuid' => 'another-company',
        'name'         => 'Foreign',
        'driver'       => 'cash',
        'environment'  => 'live',
        'status'       => 'active',
    ]));

    $foreign = $controller->savePaymentSettings(settingControllerRequest([
        'paymentSettings' => ['default_gateway_uuid' => 'foreign_public'],
    ]));
    expect($foreign->getStatusCode())->toBe(422);

    Setting::configureCompany('ledger.payment-settings', ['send_payment_receipt' => false]);
    $saved = settingControllerJson($controller->savePaymentSettings(settingControllerRequest([
        'paymentSettings' => [
            'default_gateway_uuid'   => 'gateway_public',
            'allow_partial_payments' => true,
        ],
    ])))['paymentSettings'];
    expect($saved)->toMatchArray([
        'default_gateway_uuid'   => 'gateway-uuid',
        'allow_partial_payments' => true,
        'send_payment_receipt'   => false,
    ]);
});

test('payment and accounting settings recover from malformed stored values', function () {
    $controller = new SettingController();
    Capsule::table('settings')->insert([
        [
            'key'   => 'company.company-setting-controller.ledger.payment-settings',
            'value' => json_encode('malformed'),
        ],
        [
            'key'   => 'company.company-setting-controller.ledger.accounting-settings',
            'value' => json_encode('malformed'),
        ],
    ]);

    expect(settingControllerJson($controller->getPaymentSettings())['paymentSettings'])
        ->toMatchArray(['default_gateway_uuid' => null, 'send_payment_receipt' => true])
        ->and(settingControllerJson($controller->getAccountingSettings())['accountingSettings'])
        ->toMatchArray(['base_currency' => 'USD', 'fiscal_year_start_month' => 1]);

    $payment = settingControllerJson($controller->savePaymentSettings(settingControllerRequest([
        'paymentSettings' => ['auto_apply_wallet_credit' => true],
    ])))['paymentSettings'];
    $accounting = settingControllerJson($controller->saveAccountingSettings(settingControllerRequest([
        'accountingSettings' => ['base_currency' => 'EUR'],
    ])))['accountingSettings'];
    expect($payment)->toBe(['auto_apply_wallet_credit' => true])
        ->and($accounting)->toBe(['base_currency' => 'EUR']);
});

test('accounting settings merge defaults and preserve saved partial values', function () {
    $controller = new SettingController();
    Setting::configureCompany('ledger.accounting-settings', [
        'fiscal_year_start_month' => 4,
        'default_ar_account_uuid' => 'account-ar',
    ]);

    $settings = settingControllerJson($controller->getAccountingSettings())['accountingSettings'];
    expect($settings)->toMatchArray([
        'base_currency'             => 'USD',
        'fiscal_year_start_month'   => 4,
        'auto_post_journal_entries' => false,
        'default_ar_account_uuid'   => 'account-ar',
    ]);

    $saved = settingControllerJson($controller->saveAccountingSettings(settingControllerRequest([
        'accountingSettings' => [
            'base_currency'             => 'MNT',
            'auto_post_journal_entries' => true,
        ],
    ])))['accountingSettings'];
    expect($saved)->toMatchArray([
        'base_currency'             => 'MNT',
        'fiscal_year_start_month'   => 4,
        'auto_post_journal_entries' => true,
        'default_ar_account_uuid'   => 'account-ar',
    ]);
});
