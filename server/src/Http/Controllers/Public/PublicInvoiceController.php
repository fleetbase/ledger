<?php

namespace Fleetbase\Ledger\Http\Controllers\Public;

use Fleetbase\Ledger\DTO\PurchaseRequest;
use Fleetbase\Ledger\Gateways\StripeDriver;
use Fleetbase\Ledger\Http\Resources\v1\Gateway as GatewayResource;
use Fleetbase\Ledger\Http\Resources\v1\Invoice as InvoiceResource;
use Fleetbase\Ledger\Models\Gateway;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\PaymentGatewayManager;
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
    protected PaymentGatewayManager $gatewayManager;

    public function __construct(InvoiceService $invoiceService, PaymentGatewayManager $gatewayManager)
    {
        $this->invoiceService = $invoiceService;
        $this->gatewayManager = $gatewayManager;
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
     * Initiate or record a payment against the invoice.
     *
     * Behaviour depends on the gateway driver:
     *
     *   stripe  → Creates a Stripe Checkout Session (hosted, redirect-based).
     *             Returns HTTP 200 with { checkout_url }. The frontend must
     *             redirect window.location.href to that URL. Stripe will POST
     *             a checkout.session.completed webhook when the customer pays,
     *             which HandleSuccessfulPayment will process.
     *
     *   cash / bank_transfer / other non-redirect gateways
     *           → Records the payment immediately via InvoiceService::recordPayment.
     *             Returns HTTP 200 with the updated invoice.
     *
     * Request body:
     *   gateway_id     string  required  public_id of the Gateway model
     *   reference      string  optional  Customer-provided reference / note
     */
    public function pay(Request $request, string $publicId): JsonResponse
    {
        $request->validate([
            'gateway_id' => 'required|string',
            'reference'  => 'nullable|string|max:500',
        ]);

        $invoice = $this->resolvePublicInvoice($publicId);

        if (in_array($invoice->status, ['paid', 'void', 'cancelled'])) {
            return response()->json([
                'error' => 'This invoice cannot accept payments in its current status.',
            ], 422);
        }

        // Resolve the gateway driver
        try {
            $driver = $this->gatewayManager->gateway($request->input('gateway_id'));
        } catch (\Exception $e) {
            return response()->json(['error' => 'Payment gateway not found or unavailable.'], 422);
        }

        // ── Stripe: hosted Checkout Session ───────────────────────────────────
        if ($driver instanceof StripeDriver) {
            return $this->initiateStripeCheckout($driver, $invoice, $request);
        }

        // ── All other gateways: immediate manual record ───────────────────────
        $invoice = $this->invoiceService->recordPayment($invoice, $invoice->balance, [
            'payment_method' => $driver->getCode(),
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
     * Create a Stripe Checkout Session for the given invoice and return the
     * checkout_url for the frontend to redirect to.
     *
     * success_url and cancel_url both point back to the public invoice page.
     * success_url includes ?payment=success so the frontend can show a
     * confirmation message after Stripe redirects back.
     */
    private function initiateStripeCheckout(StripeDriver $driver, Invoice $invoice, Request $request): JsonResponse
    {
        // Build the base invoice URL (the public-facing /~/invoice page)
        $invoiceUrl = rtrim(config('app.url'), '/') . '/~/invoice?id=' . $invoice->public_id;

        $successUrl = $invoiceUrl . '&payment=success';
        $cancelUrl  = $invoiceUrl . '&payment=cancelled';

        // Resolve customer email if available
        $customerEmail = null;
        if ($invoice->customer && method_exists($invoice->customer, 'getAttribute')) {
            $customerEmail = $invoice->customer->email ?? $invoice->customer->contact_email ?? null;
        }

        $purchaseRequest = new PurchaseRequest(
            amount: (int) $invoice->balance,
            currency: $invoice->currency ?? 'USD',
            description: 'Invoice ' . $invoice->number,
            customerEmail: $customerEmail,
            invoiceUuid: $invoice->uuid,
            returnUrl: $successUrl,
            cancelUrl: $cancelUrl,
            metadata: [
                'invoice_public_id' => $invoice->public_id,
                'invoice_number'    => $invoice->number,
            ],
        );

        try {
            $response = $driver->createCheckoutSession($purchaseRequest, $successUrl, $cancelUrl);
        } catch (\RuntimeException $e) {
            // Thrown by assertClientInitialized() when the gateway is missing credentials
            return response()->json([
                'error' => 'Payment gateway is not configured correctly. Please contact support.',
            ], 422);
        }

        if ($response->isFailed()) {
            return response()->json([
                'error' => $response->message ?? 'Failed to create payment session. Please try again.',
            ], 422);
        }

        return response()->json([
            'checkout_url'        => $response->data['checkout_url'],
            'checkout_session_id' => $response->data['checkout_session_id'] ?? null,
        ]);
    }

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
