<?php

use Fleetbase\Ledger\Gateways\CashDriver;
use Fleetbase\Ledger\PaymentGatewayManager;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;

beforeEach(function () {
    $capsule = new Capsule(Container::getInstance());
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'testing');
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('testing');
    Container::getInstance()->instance('db', $capsule->getDatabaseManager());
    Model::clearBootedModels();

    Capsule::schema('testing')->create('ledger_gateways', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id');
        $table->string('company_uuid');
        $table->string('driver');
        $table->string('status');
        $table->boolean('is_sandbox')->default(false);
        $table->text('config')->nullable();
        $table->string('environment')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    Capsule::table('ledger_gateways')->insert([
        [
            'uuid'         => 'gateway-company-1',
            'public_id'    => 'gateway_public_1',
            'company_uuid' => 'company-1',
            'driver'       => 'cash',
            'status'       => 'active',
            'is_sandbox'   => false,
        ],
        [
            'uuid'         => 'gateway-company-2',
            'public_id'    => 'gateway_public_2',
            'company_uuid' => 'company-2',
            'driver'       => 'cash',
            'status'       => 'active',
            'is_sandbox'   => true,
        ],
        [
            'uuid'         => 'gateway-inactive',
            'public_id'    => 'gateway_inactive',
            'company_uuid' => 'company-1',
            'driver'       => 'qpay',
            'status'       => 'inactive',
            'is_sandbox'   => false,
        ],
    ]);
});

test('gateway manager resolves active persisted gateways within the session company', function () {
    session(['company' => 'company-1']);
    $manager = new PaymentGatewayManager(Container::getInstance());

    expect($manager->gateway('gateway_public_1'))->toBeInstanceOf(CashDriver::class)
        ->and($manager->gateway('cash'))->toBeInstanceOf(CashDriver::class)
        ->and($manager->getDefaultDriver())->toBe('cash');
});

test('gateway manager resolves webhook drivers with an explicit tenant boundary', function () {
    $manager = new PaymentGatewayManager(Container::getInstance());

    expect($manager->driverForWebhook('cash', 'company-2'))->toBeInstanceOf(CashDriver::class);
});

test('gateway manager rejects inactive and cross-company persisted gateway identifiers', function () {
    session(['company' => 'company-1']);
    $manager = new PaymentGatewayManager(Container::getInstance());

    expect(fn () => $manager->gateway('gateway_public_2'))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class)
        ->and(fn () => $manager->gateway('gateway_inactive'))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});
