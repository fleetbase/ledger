<?php

namespace Fleetbase\Ledger\Observers;

use Fleetbase\Ledger\Events\InvoiceCreated;
use Fleetbase\Ledger\Events\InvoicePaid;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Services\RevenueLifecycleService;

class InvoiceObserver
{
    public function __construct(protected RevenueLifecycleService $revenueLifecycleService)
    {
    }

    /**
     * Handle the Invoice "created" event.
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
     * @return void
     */
    public function updated(Invoice $invoice)
    {
        // Fire InvoicePaid event if status changed to paid
        if ($invoice->wasChanged('status') && $invoice->status === 'paid') {
            event(new InvoicePaid($invoice));
        }

        if ($invoice->wasChanged('status') && in_array($invoice->status, ['void', 'voided', 'cancelled', 'canceled'], true)) {
            $this->revenueLifecycleService->handleInvoiceCanceled($invoice, (string) $invoice->getOriginal('status'));
        }
    }

    public function deleting(Invoice $invoice): void
    {
        if ($invoice->isForceDeleting()) {
            return;
        }

        $this->revenueLifecycleService->handleInvoiceDeleting($invoice);
    }

    public function restored(Invoice $invoice): void
    {
        $this->revenueLifecycleService->handleInvoiceRestored($invoice);
    }
}
