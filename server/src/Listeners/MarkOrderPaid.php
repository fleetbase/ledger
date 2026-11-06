<?php

namespace Fleetbase\Ledger\Listeners;

use Fleetbase\Ledger\Events\InvoicePaid;

/**
 * A paid invoice for a Fleet-Ops order is recorded on the order (`Order::markPaid()`), which is
 * what lets a rider book again after a card trip.
 */
class MarkOrderPaid
{
    public function handle(InvoicePaid $event): bool
    {
        $order = $event->invoice->order_uuid ? $this->findOrder($event->invoice->order_uuid) : null;

        // A Fleet-Ops version without `markPaid()` keeps no payment record on the order.
        return $order && method_exists($order, 'markPaid') ? (bool) $order->markPaid() : false;
    }

    protected function findOrder(string $uuid): ?object
    {
        $orderClass = 'Fleetbase\\FleetOps\\Models\\Order';

        return class_exists($orderClass) ? $orderClass::where('uuid', $uuid)->first() : null;
    }
}
