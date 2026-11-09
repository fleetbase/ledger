<?php

namespace Fleetbase\Ledger\Listeners;

use Fleetbase\Ledger\Events\InvoicePaid;
use Fleetbase\Models\Transaction;

/**
 * A paid invoice for a Fleet-Ops order settles the order's transaction (core's
 * `settlement_status`), which is how Fleet-Ops and customer apps see the order is paid.
 */
class SettleOrderTransaction
{
    public function handle(InvoicePaid $event): bool
    {
        $transaction = $this->findTransaction($event->invoice->transaction_uuid ?: $this->orderTransactionUuid($event->invoice->order_uuid));
        if (!$transaction || $transaction->settlement_status === Transaction::SETTLEMENT_STATUS_PAID) {
            return false;
        }

        $transaction->settlement_status = Transaction::SETTLEMENT_STATUS_PAID;

        return (bool) $transaction->save();
    }

    protected function findTransaction(?string $uuid): ?object
    {
        return $uuid ? Transaction::where('uuid', $uuid)->first() : null;
    }

    /**
     * The transaction of the invoice's order, for an invoice that wasn't linked to one.
     */
    protected function orderTransactionUuid(?string $orderUuid): ?string
    {
        $orderClass = 'Fleetbase\\FleetOps\\Models\\Order';

        return $orderUuid && class_exists($orderClass) ? $orderClass::where('uuid', $orderUuid)->value('transaction_uuid') : null;
    }
}
