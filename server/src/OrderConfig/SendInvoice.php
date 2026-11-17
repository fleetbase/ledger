<?php

namespace Fleetbase\Ledger\OrderConfig;

use Fleetbase\FleetOps\Contracts\OrderConfigAction;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Ledger\Models\Invoice;

/**
 * An order config action Ledger adds to Fleet-Ops: send the order's draft invoice, so the
 * customer can pay it straight away. A config runs it, for example, when an order is completed.
 */
class SendInvoice implements OrderConfigAction
{
    public function name(): string
    {
        return 'send_invoice';
    }

    public function label(): string
    {
        return 'Send invoice';
    }

    public function description(): string
    {
        return 'Send the order\'s draft invoice so the customer can pay it.';
    }

    public function parameters(): array
    {
        return [];
    }

    public function handle(Order $order, array $params = [], array $context = []): void
    {
        $invoice = $this->draftInvoice($order->uuid);
        if ($invoice) {
            $invoice->markAsSent();
        }
    }

    protected function draftInvoice(string $orderUuid): ?Invoice
    {
        return Invoice::where('order_uuid', $orderUuid)->where('status', 'draft')->latest()->first();
    }
}
