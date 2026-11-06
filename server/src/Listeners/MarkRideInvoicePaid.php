<?php

namespace Fleetbase\Ledger\Listeners;

use Fleetbase\Ledger\Events\InvoicePaid;

/**
 * A paid invoice for a Fleet-Ops ride is recorded on the order (`meta.paid_at`), which is what
 * lets the rider book again after a card trip.
 */
class MarkRideInvoicePaid
{
    public function handle(InvoicePaid $event): bool
    {
        $orderClass = 'Fleetbase\\FleetOps\\Models\\Order';
        $orderUuid  = $event->invoice->order_uuid;
        if (!$orderUuid || !class_exists($orderClass)) {
            return false;
        }

        $order = $orderClass::where('uuid', $orderUuid)->first();
        if (!$order || $order->type !== 'ride' || data_get($order, 'meta.paid_at')) {
            return false;
        }

        $order->updateMeta('paid_at', now()->toISOString());

        return true;
    }
}
