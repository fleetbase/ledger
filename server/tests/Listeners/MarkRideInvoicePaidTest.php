<?php

use Fleetbase\Ledger\Events\InvoicePaid;
use Fleetbase\Ledger\Listeners\MarkRideInvoicePaid;
use Fleetbase\Ledger\Models\Invoice;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

function bootMarkRideInvoicePaidDatabase(): void
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

    $capsule->getConnection('testing')->getSchemaBuilder()->create('orders', function (Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->unique();
        $table->string('public_id')->nullable();
        $table->string('type')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Capsule::table('orders')->insert([
        ['uuid' => 'ride-1', 'type' => 'ride', 'meta' => json_encode(['payment' => 'card'])],
        ['uuid' => 'parcel-1', 'type' => 'transport', 'meta' => json_encode([])],
    ]);
}

function markRideInvoicePaidEvent(?string $orderUuid): InvoicePaid
{
    $invoice = new Invoice();
    $invoice->setRawAttributes(['uuid' => 'invoice-1', 'order_uuid' => $orderUuid], true);

    return new InvoicePaid($invoice);
}

test('a paid invoice for a ride marks the ride paid, once', function () {
    bootMarkRideInvoicePaidDatabase();
    $listener = new MarkRideInvoicePaid();

    expect($listener->handle(markRideInvoicePaidEvent('ride-1')))->toBeTrue()
        ->and(json_decode(Capsule::table('orders')->where('uuid', 'ride-1')->value('meta'), true))->toHaveKeys(['payment', 'paid_at'])
        ->and($listener->handle(markRideInvoicePaidEvent('ride-1')))->toBeFalse();
});

test('other orders, missing orders and invoices without an order are left alone', function () {
    bootMarkRideInvoicePaidDatabase();
    $listener = new MarkRideInvoicePaid();

    expect($listener->handle(markRideInvoicePaidEvent('parcel-1')))->toBeFalse()
        ->and($listener->handle(markRideInvoicePaidEvent('gone')))->toBeFalse()
        ->and($listener->handle(markRideInvoicePaidEvent(null)))->toBeFalse()
        ->and(Capsule::table('orders')->where('uuid', 'parcel-1')->value('meta'))->toBe('[]');
});
