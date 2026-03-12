<?php

namespace Fleetbase\Ledger\Http\Controllers\Public;

use Fleetbase\Ledger\Http\Resources\v1\Gateway as GatewayResource;
use Fleetbase\Ledger\Http\Resources\v1\Invoice as InvoiceResource;
use Fleetbase\Ledger\Models\Gateway;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Public (unauthenticated) invoice controller.
 *
 * Exposes a minimal read-only view of an invoice and a payment endpoint so
 * that customers can view and pay invoices without logging in to the console.
 *
 * Routes (no auth middleware):
 *   GET  /ledger/public/invoices/{public_id}
 *   GET  /ledger/public/invoices/{public_id}/gateways
 *   POST /ledger/public/invoices/{public_id}/pay
 */
class PublicInvoiceController extends Controller
{
    protected InvoiceService $invoiceService;

    public function __construct(InvoiceService $invoiceService)
    {
        $this->invoiceService = $invoiceService;
    }

    // -------------------------------------------------------------------------
    // GET /ledger/public/invoices/{public_id}
    // -------------------------------------------------------------------------

    /**
     * Return a public-safe representation of the invoice.
     *
     * Resolves by public_id or uuid. Sensitive company internals (company_uuid,
     * created_by_uuid, etc.) are stripped because Http::isInternalRequest() will
     * return false for these unauthenticated requests.
     */
    public function show(string $publicId): JsonResponse
    {
        $invoice = $this->resolvePublicInvoice($publicId);

        // Mark the invoice as viewed on first customer access
        if (!$invoice->viewed_at) {
            $invoice->markAsViewed();
        }

        return response()->json([
            'invoice' => (new InvoiceResource($invoice->load(['customer', 'items', 'template'])))->resolve(),
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /ledger/public/invoices/{public_id}/gateways
    // -------------------------------------------------------------------------

    /**
     * Return the active payment gateways available for this invoice's company.
     *
     * Only non-sensitive fields (name, driver, capabilities, environment) are
     * returned — the config/credentials are always hidden by GatewayResource.
     */
    public function gateways(string $publicId): JsonResponse
    {
        $invoice  = $this->resolvePublicInvoice($publicId);
        $gateways = Gateway::where('company_uuid', $invoice->company_uuid)
            ->where('status', 'active')
            ->get();

        return response()->json([
            'gateways' => GatewayResource::collection($gateways)->resolve(),
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /ledger/public/invoices/{public_id}/pay
    // -------------------------------------------------------------------------

    /**
     * Record a payment against the invoice.
     *
     * For gateway-based payments the frontend should use the gateway's own
     * charge endpoint after tokenising the payment method. This endpoint is
     * intended for manual / bank-transfer / cash confirmations where the
     * customer or an operator confirms the payment amount.
     *
     * Request body:
     *   amount         int    required  Amount in smallest currency unit (cents)
     *   payment_method string optional  e.g. "bank_transfer", "cash"
     *   reference      string optional  Customer-provided reference / note
     */
    public function pay(Request $request, string $publicId): JsonResponse
    {
        $request->validate([
            'amount'         => 'required|integer|min:1',
            'payment_method' => 'nullable|string|max:100',
            'reference'      => 'nullable|string|max:500',
        ]);

        $invoice = $this->resolvePublicInvoice($publicId);

        if (in_array($invoice->status, ['paid', 'void', 'cancelled'])) {
            return response()->json([
                'error' => 'This invoice cannot accept payments in its current status.',
            ], 422);
        }

        $invoice = $this->invoiceService->recordPayment($invoice, $request->integer('amount'), [
            'payment_method' => $request->input('payment_method', 'customer_portal'),
            'reference'      => $request->input('reference'),
        ]);

        return response()->json([
            'invoice' => (new InvoiceResource($invoice->load(['customer', 'items'])))->resolve(),
            'message' => 'Payment recorded successfully.',
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve an invoice by its public_id or uuid.
     * Does NOT scope by company_uuid — the public_id is globally unique.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    private function resolvePublicInvoice(string $identifier): Invoice
    {
        return Invoice::where(function ($q) use ($identifier) {
            $q->where('public_id', $identifier)
              ->orWhere('uuid', $identifier);
        })->firstOrFail();
    }
}
