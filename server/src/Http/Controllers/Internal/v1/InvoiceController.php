<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Ledger\Http\Controllers\LedgerResourceController;
use Fleetbase\Ledger\Http\Resources\v1\Invoice as InvoiceResource;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Models\InvoiceItem;
use Fleetbase\Ledger\Services\InvoiceService;
use Fleetbase\Services\TemplateRenderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class InvoiceController extends LedgerResourceController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'invoice';

    // -------------------------------------------------------------------------
    // Lifecycle hooks — called automatically by HasApiControllerBehavior
    // -------------------------------------------------------------------------

    /**
     * Called after a new invoice record is persisted.
     * Syncs the nested items array and recalculates totals.
     *
     * Signature expected by getControllerCallback(): ($request, $record, $input)
     */
    public function onAfterCreate(Request $request, Invoice $record, array $input): void
    {
        // Use $input (the extracted payload from the 'invoice' key) rather than
        // $request->input('items') which looks at the top-level request body and
        // returns null because items are nested under invoice.items.
        $this->_syncItems($record, data_get($input, 'items', []));
        $record->calculateTotals();
        $record->save();
        $record->load(['customer', 'items', 'template']);
    }

    /**
     * Called after an existing invoice record is updated.
     * Syncs the nested items array and recalculates totals.
     *
     * Signature expected by getControllerCallback(): ($request, $record, $input)
     */
    public function onAfterUpdate(Request $request, Invoice $record, array $input): void
    {
        // Use $input (the extracted payload from the 'invoice' key) rather than
        // $request->input('items') which looks at the top-level request body and
        // returns null because items are nested under invoice.items.
        $this->_syncItems($record, data_get($input, 'items', []));
        $record->calculateTotals();
        $record->save();
        $record->load(['customer', 'items', 'template']);
    }

    // -------------------------------------------------------------------------
    // Custom endpoints
    // -------------------------------------------------------------------------

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

        return new InvoiceResource($invoice->load(['customer', 'items', 'template']));
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

        return new InvoiceResource($invoice->load(['customer', 'items', 'template']));
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

        return new InvoiceResource($invoice->load(['customer', 'items', 'template']));
    }

    /**
     * Render the invoice using its assigned template and return the HTML.
     *
     * POST /invoices/{id}/preview
     */
    public function preview(string $id, Request $request): JsonResponse
    {
        $invoice  = $this->resolveInvoice($id);
        $template = $invoice->template;

        if (!$template) {
            return response()->json(['error' => 'Invoice has no template assigned.'], 422);
        }

        // Normalise the context_type to 'invoice' so that variable paths like
        // {invoice.number} resolve correctly.  Templates created before this fix
        // may have context_type = 'ledger-invoice' stored in the database, which
        // would cause TemplateRenderService::buildContext() to key the context
        // array as 'ledger-invoice' instead of 'invoice', breaking substitution.
        $template = $this->normaliseTemplateContextType($template);

        $html = app(TemplateRenderService::class)->renderToHtml($template, $invoice);

        return response()->json(['html' => $html]);
    }

    /**
     * Render the invoice to a PDF and stream it as a download.
     *
     * POST /invoices/{id}/render-pdf
     */
    public function renderPdf(string $id, Request $request): Response
    {
        $invoice  = $this->resolveInvoice($id);
        $template = $invoice->template;

        if (!$template) {
            abort(422, 'Invoice has no template assigned.');
        }

        $template = $this->normaliseTemplateContextType($template);

        $filename = $request->input('filename', 'invoice-' . ($invoice->number ?? $invoice->id));
        $pdf      = app(TemplateRenderService::class)->renderToPdf($template, $invoice);

        return $pdf->download($filename . '.pdf');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Return a template instance whose context_type is normalised to 'invoice'.
     *
     * TemplateRenderService::buildContext() uses $template->context_type as the
     * top-level key in the variable context array.  Variable paths registered
     * by the Ledger package all use the 'invoice' namespace (e.g. {invoice.number}).
     * Templates stored in the DB before this normalisation may have
     * context_type = 'ledger-invoice', which would prevent substitution.
     *
     * We clone the model in-memory (without persisting) so the DB record is
     * not affected.
     *
     * @param  \Fleetbase\Models\Template  $template
     * @return \Fleetbase\Models\Template
     */
    private function normaliseTemplateContextType(\Fleetbase\Models\Template $template): \Fleetbase\Models\Template
    {
        if ($template->context_type === 'invoice') {
            return $template;
        }

        // Clone without touching the DB
        $clone               = clone $template;
        $clone->context_type = 'invoice';

        return $clone;
    }

    /**
     * Resolve an invoice by UUID or public_id, eager-loading all relations.
     */
    private function resolveInvoice(string $id): Invoice
    {
        return Invoice::where('company_uuid', session('company'))
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))
            ->with(['customer', 'items', 'template'])
            ->firstOrFail();
    }

    // -------------------------------------------------------------------------
    // Item sync helper
    // -------------------------------------------------------------------------

    /**
     * Upsert the nested line items array onto the given invoice.
     *
     * Strategy:
     *   1. Collect the UUIDs present in the incoming payload.
     *   2. Delete any existing items NOT in that set (removed by the user).
     *   3. For each incoming item: update if UUID exists, create if not.
     *   4. Call calculateAmount() on each item before saving.
     *
     * @param Invoice $invoice
     * @param array   $items
     */
    protected function _syncItems(Invoice $invoice, array $items): void
    {
        if (!is_array($items)) {
            return;
        }

        $incomingUuids = [];

        foreach ($items as $itemData) {
            $uuid = data_get($itemData, 'uuid');

            // Normalise client-side temporary IDs to null
            if ($uuid && (Str::startsWith($uuid, '_new_') || Str::startsWith($uuid, '_tmp_'))) {
                $uuid = null;
            }

            if ($uuid) {
                $existing = InvoiceItem::where('uuid', $uuid)
                    ->where('invoice_uuid', $invoice->uuid)
                    ->first();

                if ($existing) {
                    $existing->fill([
                        'description' => data_get($itemData, 'description'),
                        'quantity'    => (int) data_get($itemData, 'quantity', 1),
                        'unit_price'  => data_get($itemData, 'unit_price', 0),
                        'tax_rate'    => data_get($itemData, 'tax_rate', 0),
                    ]);
                    $existing->calculateAmount();
                    $existing->save();

                    $incomingUuids[] = $uuid;
                }
            } else {
                $item = new InvoiceItem([
                    'invoice_uuid' => $invoice->uuid,
                    'description'  => data_get($itemData, 'description'),
                    'quantity'     => (int) data_get($itemData, 'quantity', 1),
                    'unit_price'   => data_get($itemData, 'unit_price', 0),
                    'tax_rate'     => data_get($itemData, 'tax_rate', 0),
                ]);
                $item->calculateAmount();
                $item->save();

                $incomingUuids[] = $item->uuid;
            }
        }

        // Remove items that were deleted in the form
        $invoice->items()
            ->whereNotIn('uuid', $incomingUuids)
            ->delete();
    }
}
