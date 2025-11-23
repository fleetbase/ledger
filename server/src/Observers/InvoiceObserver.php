<?php

namespace Fleetbase\Ledger\Observers;

use Fleetbase\Ledger\Events\InvoiceCreated;
use Fleetbase\Ledger\Events\InvoicePaid;
use Fleetbase\Ledger\Models\Invoice;

class InvoiceObserver
{
    /**
     * Handle the Invoice "created" event.
     *
     * @param Invoice $invoice
     *
     * @return void
     */
    public function created(Invoice $invoice)
    {
        event(new InvoiceCreated($invoice));
    }

    /**
     * Handle the Invoice "updated" event.
     *
     * @param Invoice $invoice
     *
     * @return void
     */
    public function updated(Invoice $invoice)
    {
        // Fire InvoicePaid event if status changed to paid
        if ($invoice->isDirty('status') && $invoice->status === 'paid') {
            event(new InvoicePaid($invoice));
        }
    }
}
