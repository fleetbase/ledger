<?php

namespace Fleetbase\Ledger\Http\Controllers\Public;

use Fleetbase\Ledger\DTO\GatewayResponse;
use Fleetbase\Ledger\DTO\PurchaseRequest;
use Fleetbase\Ledger\Gateways\CashDriver;
use Fleetbase\Ledger\Gateways\StripeDriver;
use Fleetbase\Ledger\Http\Resources\v1\Gateway as GatewayResource;
use Fleetbase\Ledger\Http\Resources\v1\Invoice as InvoiceResource;
use Fleetbase\Ledger\Models\Gateway;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\PaymentGatewayManager;
use Fleetbase\Ledger\Services\InvoiceService;
use Fleetbase\Ledger\Services\PaymentService;
use Fleetbase\Support\Utils;
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
    protected PaymentService $paymentService;

    public function __construct(InvoiceService $invoiceService, PaymentGatewayManager $gatewayManager, PaymentService $paymentService)
    {
        $this->invoiceService = $invoiceService;
        $this->gatewayManager = $gatewayManager;
        $this->paymentService = $paymentService;
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

        // Draft invoices are internal-only and must not be accessible on the
        // public URL. Return 403 so the frontend can show a "not available" page.
        if ($invoice->status === 'draft') {
            return response()->json([
                'error' => 'This invoice is not yet available. Please contact the sender.',
            ], 403);
        }

        // Mark the invoice as viewed on first customer access.
        // Also auto-transitions status from 'sent' → 'viewed' so the sender
        // can see their customer has opened the invoice.
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
     *   taler/qpay/other pending gateways
     *           → Creates a gateway transaction and returns { payment_url } or
     *             gateway-specific payment data. The invoice is only marked paid
     *             after the gateway webhook confirms payment.
     *
     *   cash    → Records the payment immediately via InvoiceService::recordPayment.
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

        $gateway = Gateway::query()
            ->where('company_uuid', $invoice->company_uuid)
            ->where('status', 'active')
            ->where(function ($query) use ($request) {
                $gatewayId = $request->input('gateway_id');

                $query->where('uuid', $gatewayId)
                    ->orWhere('public_id', $gatewayId);
            })
            ->first();

        if (!$gateway) {
            return response()->json(['error' => 'Payment gateway not found or unavailable.'], 422);
        }

        $driver = $this->gatewayManager->driver($gateway->driver)
            ->initialize($gateway->decryptedConfig(), $gateway->is_sandbox);

        // ── Stripe: hosted Checkout Session ───────────────────────────────────
        if ($driver instanceof StripeDriver) {
            return $this->initiateStripeCheckout($driver, $invoice, $request);
        }

        // ── Cash/manual: immediate local record ────────────────────────────────
        if ($driver instanceof CashDriver) {
            return $this->recordManualPayment($driver, $invoice, $request);
        }

        // ── Redirect / asynchronous gateways: create gateway charge ───────────
        $response = $this->paymentService->charge(
            $gateway->public_id ?? $gateway->uuid,
            $this->buildPurchaseRequest($invoice, [
                'gateway_public_id' => $gateway->public_id,
                'gateway_uuid'      => $gateway->uuid,
                'gateway_driver'    => $gateway->driver,
                'company_uuid'      => $invoice->company_uuid,
            ])
        );

        if ($response->isFailed()) {
            return response()->json([
                'error' => $response->message ?? 'Failed to initiate payment. Please try again.',
            ], 422);
        }

        if ($response->isPending()) {
            return response()->json($this->pendingPaymentPayload($response, $gateway));
        }

        return response()->json([
            'status'                 => $response->status,
            'payment_status'         => $response->status,
            'gateway_transaction_id' => $response->gatewayTransactionId,
            'message'                => $response->message ?? 'Payment processed successfully.',
            'invoice'                => (new InvoiceResource($invoice->fresh(['customer', 'items'])))->resolve(),
        ]);
    }

    private function recordManualPayment(CashDriver $driver, Invoice $invoice, Request $request): JsonResponse
    {
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
        // Build the redirect URLs pointing to the console's public invoice page.
        // Utils::consoleUrl reads fleetbase.console.host (CONSOLE_HOST env var) so
        // the redirect always targets the frontend host, never the API host.
        $successUrl = Utils::consoleUrl('~/invoice', [
            'id'      => $invoice->public_id,
            'payment' => 'success',
        ]);
        $cancelUrl = Utils::consoleUrl('~/invoice', [
            'id'      => $invoice->public_id,
            'payment' => 'cancelled',
        ]);

        $purchaseRequest = $this->buildPurchaseRequest($invoice);

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

        return response()->json(array_merge($this->pendingPaymentPayload($response, null), [
            'checkout_url'        => $response->data['checkout_url'],
            'checkout_session_id' => $response->data['checkout_session_id'] ?? null,
        ]));
    }

    private function buildPurchaseRequest(Invoice $invoice, array $metadata = []): PurchaseRequest
    {
        $successUrl = Utils::consoleUrl('~/invoice', [
            'id'      => $invoice->public_id,
            'payment' => 'success',
        ]);
        $cancelUrl = Utils::consoleUrl('~/invoice', [
            'id'      => $invoice->public_id,
            'payment' => 'cancelled',
        ]);

        $customerEmail = null;
        if ($invoice->customer && method_exists($invoice->customer, 'getAttribute')) {
            $customerEmail = $invoice->customer->email ?? $invoice->customer->contact_email ?? null;
        }

        return new PurchaseRequest(
            amount: (int) $invoice->balance,
            currency: $invoice->currency ?? 'USD',
            description: 'Invoice ' . $invoice->number,
            customerEmail: $customerEmail,
            invoiceUuid: $invoice->uuid,
            returnUrl: $successUrl,
            cancelUrl: $cancelUrl,
            metadata: array_merge([
                'invoice_public_id' => $invoice->public_id,
                'invoice_number'    => $invoice->number,
            ], $metadata),
        );
    }

    private function pendingPaymentPayload(GatewayResponse $response, ?Gateway $gateway): array
    {
        $paymentUrl = $response->data['checkout_url']
            ?? $response->data['payment_url']
            ?? $response->data['taler_pay_uri']
            ?? data_get($response->data, 'urls.0.link');

        $payload = [
            'status'                 => GatewayResponse::STATUS_PENDING,
            'payment_status'         => GatewayResponse::STATUS_PENDING,
            'gateway_transaction_id' => $response->gatewayTransactionId,
            'payment_url'            => $paymentUrl,
            'payment_uri'            => $paymentUrl,
            'message'                => $response->message,
            'qr_image'               => $response->data['qr_image'] ?? null,
            'qr_text'                => $response->data['qr_text'] ?? $paymentUrl,
            'data'                   => $response->data,
        ];

        if ($gateway) {
            $payload['gateway'] = [
                'id'     => $gateway->public_id,
                'driver' => $gateway->driver,
                'name'   => $gateway->name,
            ];
        }

        return $payload;
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
