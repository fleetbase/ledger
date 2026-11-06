<?php

use Fleetbase\Ledger\Events\InvoicePaid;
use Fleetbase\Ledger\Listeners\MarkOrderPaid;
use Fleetbase\Ledger\Models\Invoice;

/** An order that records being marked paid, as Fleet-Ops orders do. */
class MarkOrderPaidOrder
{
    public int $marked = 0;

    public function markPaid(): bool
    {
        return ++$this->marked === 1;
    }
}

class MarkOrderPaidProbe extends MarkOrderPaid
{
    public array $orders = [];

    protected function findOrder(string $uuid): ?object
    {
        return $this->orders[$uuid] ?? null;
    }
}

function markOrderPaidEvent(?string $orderUuid): InvoicePaid
{
    $invoice = new Invoice();
    $invoice->setRawAttributes(['uuid' => 'invoice-1', 'order_uuid' => $orderUuid], true);

    return new InvoicePaid($invoice);
}

test('a paid invoice marks its order paid, once', function () {
    $listener                   = new MarkOrderPaidProbe();
    $listener->orders['ride-1'] = $order = new MarkOrderPaidOrder();

    expect($listener->handle(markOrderPaidEvent('ride-1')))->toBeTrue()
        ->and($listener->handle(markOrderPaidEvent('ride-1')))->toBeFalse()
        ->and($order->marked)->toBe(2);
});

test('missing orders, invoices without one, and orders that keep no payment record are left alone', function () {
    $listener                     = new MarkOrderPaidProbe();
    $listener->orders['legacy-1'] = new stdClass();

    expect($listener->handle(markOrderPaidEvent('gone')))->toBeFalse()
        ->and($listener->handle(markOrderPaidEvent(null)))->toBeFalse()
        ->and($listener->handle(markOrderPaidEvent('legacy-1')))->toBeFalse();
});

test('orders are looked up through Fleet-Ops', function () {
    $capsule = new Illuminate\Database\Capsule\Manager(Illuminate\Container\Container::getInstance());
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'testing');
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('testing');
    Illuminate\Container\Container::getInstance()->instance('db', $capsule->getDatabaseManager());
    Illuminate\Support\Facades\Facade::clearResolvedInstance('db');
    $capsule->getConnection('testing')->getSchemaBuilder()->create('orders', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->timestamps();
        $table->softDeletes();
    });
    Illuminate\Database\Capsule\Manager::table('orders')->insert(['uuid' => 'ride-1']);
    $lookup = new ReflectionMethod(MarkOrderPaid::class, 'findOrder');

    expect($lookup->invoke(new MarkOrderPaid(), 'ride-1')?->uuid)->toBe('ride-1')
        ->and($lookup->invoke(new MarkOrderPaid(), 'gone'))->toBeNull();
});
