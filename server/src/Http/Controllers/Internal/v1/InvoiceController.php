<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Ledger\Http\Controllers\LedgerResourceController;
use Fleetbase\Ledger\Http\Resources\v1\Invoice as InvoiceResource;
use Fleetbase\Ledger\Http\Resources\v1\Transaction as TransactionResource;
use Fleetbase\Ledger\DTO\RefundRequest;
use Fleetbase\Ledger\Models\GatewayTransaction;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Models\InvoiceItem;
use Fleetbase\Ledger\Models\Transaction;
use Fleetbase\Ledger\Services\InvoiceService;
use Fleetbase\Ledger\Services\PaymentService;
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
        $this->_syncItems($record, data_get($input, 'items', []));
        $record->calculateTotals();
        $record->save();
        // Recognise revenue now that totals are finalised (Debit AR, Credit Revenue)
        app(InvoiceService::class)->recogniseRevenue($record);
        $record = app(InvoiceService::class)->autoSendOnCreation($record);
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
            'amount'         => 'required|integer|min:1',
            'payment_method' => 'nullable|string',
            'reference'      => 'nullable|string',
        ]);

        $invoice = Invoice::where('company_uuid', session('company'))
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))
            ->firstOrFail();

        $invoice = app(InvoiceService::class)->recordPayment($invoice, $request->input('amount'), [
            'payment_method' => $request->input('payment_method', 'manual'),
            'reference'      => $request->input('reference'),
        ]);

        return new InvoiceResource($invoice->load(['customer', 'items', 'template']));
    }

    /**
     * List transactions related to an invoice.
     */
    public function transactions(string $id, Request $request)
    {
        $invoice = Invoice::where('company_uuid', session('company'))
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))
            ->firstOrFail();

        $transactions = Transaction::where('company_uuid', session('company'))
            ->where(function ($query) use ($invoice) {
                $query->where('subject_uuid', $invoice->uuid)
                    ->orWhere('context_uuid', $invoice->uuid);

                if ($invoice->transaction_uuid) {
                    $query->orWhere('uuid', $invoice->transaction_uuid);
                }
            })
            ->with([
                'items',
                'journal.debitAccount',
                'journal.creditAccount',
                'subject',
                'payer',
                'payee',
                'initiator',
                'context',
            ])
            ->orderBy('created_at', $request->input('sort') === 'created_at' ? 'asc' : 'desc')
            ->paginate($request->integer('limit', 50));

        TransactionResource::wrap('transactions');

        return TransactionResource::collection($transactions);
    }

    /**
     * Return gateway payments on this invoice that can still be refunded.
     */
    public function refundOptions(string $id, Request $request): JsonResponse
    {
        $invoice = $this->resolveInvoice($id);
        $options = $this->refundableGatewayTransactions($invoice);

        return response()->json([
            'invoice' => [
                'id'                        => $invoice->public_id,
                'uuid'                      => $invoice->uuid,
                'number'                    => $invoice->number,
                'currency'                  => $invoice->currency,
                'total_amount'              => (int) $invoice->total_amount,
                'amount_paid'               => (int) $invoice->amount_paid,
                'refunded_amount'           => $this->invoiceRefundedAmount($invoice),
                'remaining_refundable_amount' => $this->invoiceRemainingRefundableAmount($invoice),
            ],
            'options' => $options,
        ]);
    }

    /**
     * Issue a refund against a refundable gateway payment on this invoice.
     */
    public function refund(string $id, Request $request, PaymentService $paymentService): JsonResponse
    {
        $invoice = $this->resolveInvoice($id);

        $request->validate([
            'gateway_transaction_id' => 'required|string',
            'amount'                 => 'required|integer|min:1',
            'reason'                 => 'nullable|string|max:500',
        ]);

        $options = collect($this->refundableGatewayTransactions($invoice));
        $selected = $options->firstWhere('gateway_transaction_id', $request->input('gateway_transaction_id'));

        if (!$selected) {
            return response()->json(['error' => 'The selected payment cannot be refunded.'], 422);
        }

        $amount = $request->integer('amount');
        $remainingInvoiceAmount = $this->invoiceRemainingRefundableAmount($invoice);
        $remainingPaymentAmount = (int) data_get($selected, 'refundable_amount', 0);
        $maxRefundableAmount = min($remainingInvoiceAmount, $remainingPaymentAmount);

        if ($amount > $maxRefundableAmount) {
            return response()->json([
                'error' => 'Refund amount exceeds the remaining refundable amount.',
                'remaining_refundable_amount' => $maxRefundableAmount,
            ], 422);
        }

        $refundKind = $amount >= $remainingInvoiceAmount ? 'full' : 'partial';

        $response = $paymentService->refund(data_get($selected, 'gateway.id'), new RefundRequest(
            gatewayTransactionId: data_get($selected, 'gateway_transaction_id'),
            amount: $amount,
            currency: $invoice->currency ?? data_get($selected, 'currency', 'USD'),
            reason: $request->input('reason'),
            invoiceUuid: $invoice->uuid,
            metadata: [
                'refund_kind' => $refundKind,
                'invoice_uuid' => $invoice->uuid,
                'invoice_public_id' => $invoice->public_id,
            ],
        ));

        return response()->json([
            'status'                 => $response->status,
            'successful'             => $response->successful,
            'gateway_transaction_id' => $response->gatewayTransactionId,
            'message'                => $response->message,
            'data'                   => $response->data,
            'refund_kind'            => $refundKind,
            'invoice'                => (new InvoiceResource($invoice->fresh(['customer', 'items', 'template'])))->resolve(),
        ], $response->isSuccessful() ? 200 : 422);
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

        try {
            $invoice = app(InvoiceService::class)->send($invoice);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

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

    private function invoiceRefundedAmount(Invoice $invoice): int
    {
        return (int) data_get($invoice->meta, 'refunded_amount', 0);
    }

    private function invoiceRemainingRefundableAmount(Invoice $invoice): int
    {
        return max(0, (int) $invoice->amount_paid - $this->invoiceRefundedAmount($invoice));
    }

    private function refundableGatewayTransactions(Invoice $invoice): array
    {
        if (in_array($invoice->status, ['draft', 'void', 'cancelled'], true)) {
            return [];
        }

        $remainingInvoiceAmount = $this->invoiceRemainingRefundableAmount($invoice);

        if ($remainingInvoiceAmount <= 0) {
            return [];
        }

        $paymentReferences = Transaction::where('company_uuid', $invoice->company_uuid)
            ->where('context_uuid', $invoice->uuid)
            ->where('type', 'invoice_payment')
            ->whereNotNull('reference')
            ->pluck('reference')
            ->filter()
            ->values();

        $gatewayTransactions = GatewayTransaction::query()
            ->with('gateway')
            ->where('company_uuid', $invoice->company_uuid)
            ->whereIn('type', ['purchase', 'webhook_event'])
            ->whereIn('status', ['succeeded', 'refunded'])
            ->where(function ($query) use ($invoice, $paymentReferences) {
                $query->where('raw_response->invoice_uuid', $invoice->uuid)
                    ->orWhere('raw_response->data->invoice_uuid', $invoice->uuid)
                    ->orWhere('raw_response->metadata->invoice_uuid', $invoice->uuid);

                if ($paymentReferences->isNotEmpty()) {
                    $query->orWhereIn('gateway_reference_id', $paymentReferences->all());
                }
            })
            ->orderByDesc('created_at')
            ->get()
            ->unique(fn (GatewayTransaction $transaction) => $transaction->gateway_uuid . ':' . $transaction->gateway_reference_id)
            ->values();

        return $gatewayTransactions
            ->map(function (GatewayTransaction $transaction) use ($invoice, $remainingInvoiceAmount) {
                $paidAmount = (int) ($transaction->amount ?: $invoice->amount_paid);
                $refundedAmount = (int) GatewayTransaction::where('company_uuid', $invoice->company_uuid)
                    ->where('gateway_uuid', $transaction->gateway_uuid)
                    ->where('gateway_reference_id', $transaction->gateway_reference_id)
                    ->where('type', 'refund')
                    ->whereNotIn('status', ['failed'])
                    ->sum('amount');
                $remainingPaymentAmount = max(0, $paidAmount - $refundedAmount);
                $refundableAmount = min($remainingInvoiceAmount, $remainingPaymentAmount);

                if (!$transaction->gateway || $refundableAmount <= 0) {
                    return null;
                }

                return [
                    'id'                     => $transaction->public_id,
                    'uuid'                   => $transaction->uuid,
                    'gateway_transaction_id' => $transaction->gateway_reference_id,
                    'amount'                 => $paidAmount,
                    'currency'               => $transaction->currency ?? $invoice->currency,
                    'refunded_amount'        => $refundedAmount,
                    'refundable_amount'      => $refundableAmount,
                    'status'                 => $transaction->status,
                    'created_at'             => optional($transaction->created_at)->toISOString(),
                    'requires_customer_action' => $transaction->gateway->driver === 'taler',
                    'gateway'                => [
                        'id'        => $transaction->gateway->public_id ?? $transaction->gateway->uuid,
                        'uuid'      => $transaction->gateway->uuid,
                        'public_id' => $transaction->gateway->public_id,
                        'name'      => $transaction->gateway->name,
                        'driver'    => $transaction->gateway->driver,
                    ],
                ];
            })
            ->filter()
            ->values()
            ->all();
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
     */
    protected function _syncItems(Invoice $invoice, array $items): void
    {
        if (!is_array($items)) {
            return;
        }

        // Validate that every item has a description
        foreach ($items as $index => $itemData) {
            $description = trim((string) data_get($itemData, 'description', ''));
            if ($description === '') {
                $line = $index + 1;
                abort(422, "Line item {$line} is missing a description.");
            }
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
