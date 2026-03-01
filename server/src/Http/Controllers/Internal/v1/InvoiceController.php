<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Ledger\Http\Controllers\LedgerController;
use Fleetbase\Ledger\Http\Resources\v1\Invoice as InvoiceResource;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Services\InvoiceService;
use Illuminate\Http\Request;

class InvoiceController extends LedgerController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'invoice';

    /**
     * Create an invoice from an existing order.
     */
    public function createFromOrder(Request $request): InvoiceResource
    {
        $request->validate([
            'order_uuid' => 'required|string|exists:orders,uuid',
        ]);

        $order = \Fleetbase\FleetOps\Models\Order::where('company_uuid', session('company'))
            ->where('uuid', $request->input('order_uuid'))
            ->firstOrFail();

        $invoice = app(InvoiceService::class)->createFromOrder($order);

        return new InvoiceResource($invoice->load(['customer', 'items']));
    }

    /**
     * Record a payment against an invoice.
     */
    public function recordPayment(string $id, Request $request): InvoiceResource
    {
        $request->validate([
            'amount' => 'required|integer|min:1',
        ]);

        $invoice = Invoice::where('company_uuid', session('company'))
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))
            ->firstOrFail();

        $invoice = app(InvoiceService::class)->recordPayment($invoice, $request->input('amount'));

        return new InvoiceResource($invoice->load(['customer', 'items']));
    }

    /**
     * Mark an invoice as sent (without dispatching a notification).
     */
    public function markAsSent(string $id, Request $request): InvoiceResource
    {
        $invoice = Invoice::where('company_uuid', session('company'))
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))
            ->firstOrFail();

        $invoice->markAsSent();

        return new InvoiceResource($invoice);
    }

    /**
     * Send an invoice to the customer via email and mark it as sent.
     */
    public function send(string $id, Request $request): InvoiceResource
    {
        $invoice = Invoice::where('company_uuid', session('company'))
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))
            ->with('customer')
            ->firstOrFail();

        if (!$invoice->customer || !$invoice->customer->email) {
            abort(422, 'Invoice customer does not have a valid email address.');
        }

        $invoice->markAsSent();

        // TODO (M5): Dispatch InvoiceSentNotification to $invoice->customer->email

        return new InvoiceResource($invoice->load(['customer', 'items']));
    }
}
