<?php

namespace Fleetbase\Ledger\Events;

use Fleetbase\Ledger\Models\Invoice;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InvoicePaid
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * The invoice instance.
     *
     * @var \Fleetbase\Ledger\Models\Invoice
     */
    public Invoice $invoice;

    /**
     * Create a new event instance.
     *
     * @param Invoice $invoice
     *
     * @return void
     */
    public function __construct(Invoice $invoice)
    {
        $this->invoice = $invoice;
    }
}
