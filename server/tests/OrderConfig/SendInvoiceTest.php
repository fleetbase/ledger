<?php

use Fleetbase\FleetOps\Contracts\OrderConfigAction;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Ledger\OrderConfig\SendInvoice;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

function bootSendInvoiceDatabase(): void
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

    $schema = $capsule->getConnection('testing')->getSchemaBuilder();
    $schema->create('ledger_invoices', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('order_uuid')->nullable();
        $table->string('status');
        $table->timestamp('sent_at')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('ledger_invoice_items', function (Blueprint $table) {
        $table->increments('id');
        $table->string('invoice_uuid');
        $table->softDeletes();
    });
    // Invoices eager-load their order (and its tracking number) and template.
    foreach (['orders', 'tracking_numbers', 'templates'] as $tableName) {
        $schema->create($tableName, function (Blueprint $table) {
            $table->string('uuid')->primary();
            $table->string('tracking_number_uuid')->nullable();
            $table->softDeletes();
        });
    }
}

function sendInvoiceOrder(string $uuid): Order
{
    $order = new Order();
    $order->setRawAttributes(['uuid' => $uuid], true);

    return $order;
}

/** @return array<string, array{status: string, sent: bool}> */
function sendInvoiceStatuses(): array
{
    return Capsule::table('ledger_invoices')->orderBy('uuid')->get()
        ->mapWithKeys(fn ($row) => [$row->uuid => ['status' => $row->status, 'sent' => $row->sent_at !== null]])
        ->all();
}

beforeEach(function () {
    bootSendInvoiceDatabase();
});

test('send invoice describes itself to fleet-ops order configs', function () {
    $action = new SendInvoice();

    expect($action)->toBeInstanceOf(OrderConfigAction::class)
        ->and($action->name())->toBe('send_invoice')
        ->and($action->label())->toBe('Send invoice')
        ->and($action->description())->toBe('Send the order\'s draft invoice so the customer can pay it.')
        ->and($action->parameters())->toBe([]);
});

test('send invoice sends only the order\'s newest draft invoice', function () {
    Capsule::table('ledger_invoices')->insert([
        ['uuid' => 'a-older-draft', 'order_uuid' => 'order-1', 'status' => 'draft', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'],
        ['uuid' => 'b-newest-draft', 'order_uuid' => 'order-1', 'status' => 'draft', 'created_at' => '2026-01-03 00:00:00', 'updated_at' => '2026-01-03 00:00:00'],
        ['uuid' => 'c-paid', 'order_uuid' => 'order-1', 'status' => 'paid', 'created_at' => '2026-01-05 00:00:00', 'updated_at' => '2026-01-05 00:00:00'],
        ['uuid' => 'd-other-order', 'order_uuid' => 'order-2', 'status' => 'draft', 'created_at' => '2026-01-06 00:00:00', 'updated_at' => '2026-01-06 00:00:00'],
    ]);

    (new SendInvoice())->handle(sendInvoiceOrder('order-1'));

    expect(sendInvoiceStatuses())->toBe([
        'a-older-draft'  => ['status' => 'draft', 'sent' => false],
        'b-newest-draft' => ['status' => 'sent', 'sent' => true],
        'c-paid'         => ['status' => 'paid', 'sent' => false],
        'd-other-order'  => ['status' => 'draft', 'sent' => false],
    ]);
});

test('send invoice leaves an order without a draft invoice alone', function () {
    Capsule::table('ledger_invoices')->insert([
        ['uuid' => 'sent-invoice', 'order_uuid' => 'order-1', 'status' => 'sent', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'],
    ]);

    (new SendInvoice())->handle(sendInvoiceOrder('order-1'));
    (new SendInvoice())->handle(sendInvoiceOrder('order-without-invoices'));

    expect(sendInvoiceStatuses())->toBe([
        'sent-invoice' => ['status' => 'sent', 'sent' => false],
    ]);
});
