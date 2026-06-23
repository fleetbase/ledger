<?php

namespace Fleetbase\Ledger\Gateways;

use Fleetbase\Ledger\DTO\GatewayResponse;
use Fleetbase\Ledger\DTO\PurchaseRequest;
use Fleetbase\Ledger\DTO\RefundRequest;
use Fleetbase\Ledger\Exceptions\WebhookSignatureException;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Milon\Barcode\Facades\DNS2DFacade as DNS2D;

/**
 * TalerDriver.
 *
 * Payment gateway driver for GNU Taler — a privacy-preserving electronic
 * payment system that provides customer anonymity while ensuring merchants
 * remain fully accountable.
 *
 * GNU Taler uses a Merchant Backend REST API. This driver communicates with
 * a self-hosted or third-party Taler Merchant Backend to:
 *
 *   1. Create an order (purchase) and return a taler_pay_uri for the wallet.
 *   2. Verify incoming webhook notifications by re-querying the private API.
 *   3. Issue refunds against a previously paid order.
 *
 * Configuration (stored encrypted in ledger_gateways.config):
 *   - backend_url   : Base URL of the Taler Merchant Backend (e.g. https://backend.demo.taler.net/)
 *   - instance_id   : Merchant instance ID (defaults to "default")
 *   - api_token     : Bearer token for authenticating against the private API
 *
 * Amount encoding:
 *   Fleetbase stores all monetary values as integers in the smallest currency
 *   unit (e.g. 1050 = USD 10.50). Taler encodes amounts as strings in the
 *   format "CURRENCY:UNITS.FRACTION" (e.g. "USD:10.50"). This driver converts
 *   between the two representations in both directions.
 *
 * Payment flow:
 *   purchase()      → POST /instances/{id}/private/orders
 *                   → GET  /instances/{id}/private/orders/{order_id}  (for taler_pay_uri)
 *                   → GatewayResponse::pending() with taler_pay_uri in data[]
 *
 *   handleWebhook() → POST /ledger/webhooks/taler  (receives order_id from Taler)
 *                   → GET  /instances/{id}/private/orders/{order_id}  (verify payment)
 *                   → GatewayResponse::success() dispatches HandleSuccessfulPayment
 *
 *   refund()        → POST /instances/{id}/private/orders/{order_id}/refund
 *                   → GatewayResponse::success() dispatches HandleProcessedRefund
 *
 * @see https://docs.taler.net/core/api-merchant.html
 */
class TalerDriver extends AbstractGatewayDriver
{
    // -------------------------------------------------------------------------
    // Driver Identity & Metadata
    // -------------------------------------------------------------------------

    public function getName(): string
    {
        return 'GNU Taler';
    }

    public function getCode(): string
    {
        return 'taler';
    }

    /**
     * {@inheritdoc}
     *
     * GNU Taler supports direct purchases (via wallet redirect), refunds, and
     * webhook-based payment confirmation. It does not support card tokenization.
     */
    public function getCapabilities(): array
    {
        return [
            'purchase',
            'refund',
            'webhooks',
            'sandbox',
        ];
    }

    /**
     * {@inheritdoc}
     *
     * Returns the configuration schema rendered dynamically by the Fleetbase
     * Ledger gateway form component. All fields are stored encrypted.
     */
    public function getConfigSchema(): array
    {
        return [
            [
                'key'         => 'backend_url',
                'label'       => 'Merchant Backend URL',
                'type'        => 'text',
                'required'    => true,
                'hint'        => 'Base URL of your Taler Merchant Backend, e.g. https://backend.demo.taler.net/',
                'description' => 'Base URL of your Taler Merchant Backend, e.g. https://backend.demo.taler.net/',
            ],
            [
                'key'         => 'instance_id',
                'label'       => 'Instance ID',
                'type'        => 'text',
                'required'    => true,
                'default'     => 'default',
                'hint'        => 'The Taler merchant instance identifier. Defaults to "default".',
                'description' => 'The Taler merchant instance identifier. Defaults to "default".',
            ],
            [
                'key'         => 'api_token',
                'label'       => 'API Token',
                'type'        => 'password',
                'required'    => true,
                'hint'        => 'Bearer token for authenticating against the private Merchant API.',
                'description' => 'Bearer token for authenticating against the private Merchant API.',
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Purchase
    // -------------------------------------------------------------------------

    /**
     * {@inheritdoc}
     *
     * Creates a new Taler order via the Merchant Backend and returns a pending
     * response containing the taler_pay_uri that the customer's Taler wallet
     * must open to complete the payment.
     *
     * The Fleetbase invoice UUID is embedded in the order's contract terms
     * under the key "invoice_uuid" so that it can be recovered during webhook
     * processing without any additional storage.
     *
     * Steps:
     *   1. Convert integer cents → Taler amount string.
     *   2. POST to /instances/{id}/private/orders.
     *   3. GET  /instances/{id}/private/orders/{order_id} to obtain taler_pay_uri.
     *   4. Return GatewayResponse::pending() with order_id and taler_pay_uri.
     *
     * @param PurchaseRequest $request Immutable purchase request DTO
     *
     * @return GatewayResponse Pending response with taler_pay_uri in data[]
     */
    public function purchase(PurchaseRequest $request): GatewayResponse
    {
        if ($configurationFailure = $this->configurationFailureResponse()) {
            return $configurationFailure;
        }

        $instanceId = $this->instanceId();

        $talerAmount = $this->toTalerAmount($request->amount, $request->currency);
        $orderId     = $this->orderIdForPurchase($request);

        // Build the order payload. The invoice_uuid is stored as a top-level
        // field in the order object so it is included in the signed contract
        // terms and can be retrieved verbatim when the webhook fires.
        $payload = [
            'order_id' => $orderId,
            'order'    => [
                'amount'       => $talerAmount,
                'summary'      => $request->description,
                'invoice_uuid' => $request->invoiceUuid,
                'metadata'     => array_filter([
                    'invoice_uuid'      => $request->invoiceUuid,
                    'invoice_public_id' => $request->metadata['invoice_public_id'] ?? null,
                    'invoice_number'    => $request->metadata['invoice_number'] ?? null,
                    'order_uuid'        => $request->orderUuid,
                    'gateway_public_id' => $request->metadata['gateway_public_id'] ?? null,
                    'gateway_uuid'      => $request->metadata['gateway_uuid'] ?? null,
                    'company_uuid'      => $request->metadata['company_uuid'] ?? null,
                ]),
            ],
        ];

        // Append fulfillment / return URLs when provided by the caller.
        if ($request->returnUrl) {
            $payload['order']['fulfillment_url'] = $request->returnUrl;
        }

        $this->logInfo('Creating Taler order', [
            'amount'       => $talerAmount,
            'order_id'     => $orderId,
            'invoice_uuid' => $request->invoiceUuid,
        ]);

        try {
            $createResponse = $this->privateRequest('POST', "instances/{$instanceId}/private/orders", $payload);
        } catch (\Throwable $e) {
            $this->logError('Order creation HTTP error', ['error' => $e->getMessage()]);

            return GatewayResponse::failure(
                eventType: GatewayResponse::EVENT_PAYMENT_FAILED,
                message: 'Taler order creation failed: ' . $e->getMessage(),
                rawResponse: ['error' => $e->getMessage()],
            );
        }

        if (!$createResponse->successful()) {
            $this->logError('Order creation failed', [
                'status' => $createResponse->status(),
                'body'   => $createResponse->body(),
            ]);

            return GatewayResponse::failure(
                eventType: GatewayResponse::EVENT_PAYMENT_FAILED,
                message: 'Taler order creation failed: ' . $createResponse->body(),
                rawResponse: $createResponse->json() ?? [],
            );
        }

        $orderId = $createResponse->json('order_id');

        if (!$orderId) {
            return GatewayResponse::failure(
                eventType: GatewayResponse::EVENT_PAYMENT_FAILED,
                message: 'Taler returned no order_id in creation response.',
                rawResponse: $createResponse->json() ?? [],
            );
        }

        // Retrieve the order status to obtain the taler_pay_uri. The URI is
        // only available on the status endpoint, not the creation response.
        $talerPayUri    = null;
        $orderStatusRaw = [];

        try {
            $statusResponse = $this->privateRequest('GET', "instances/{$instanceId}/private/orders/{$orderId}");

            if ($statusResponse->successful()) {
                $talerPayUri    = $statusResponse->json('taler_pay_uri');
                $orderStatusRaw = $statusResponse->json() ?? [];
            }
        } catch (\Throwable $e) {
            $this->logError('Could not retrieve taler_pay_uri after order creation', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);

            return GatewayResponse::failure(
                gatewayTransactionId: $orderId,
                eventType: GatewayResponse::EVENT_PAYMENT_FAILED,
                message: 'Taler order created, but payment URI retrieval failed: ' . $e->getMessage(),
                rawResponse: ['error' => $e->getMessage(), 'order_id' => $orderId],
            );
        }

        if (!$talerPayUri) {
            return GatewayResponse::failure(
                gatewayTransactionId: $orderId,
                eventType: GatewayResponse::EVENT_PAYMENT_FAILED,
                message: 'Taler order created, but no taler_pay_uri was returned.',
                rawResponse: array_merge($createResponse->json() ?? [], ['status' => $orderStatusRaw]),
            );
        }

        $this->logInfo('Taler order created', [
            'order_id'     => $orderId,
            'invoice_uuid' => $request->invoiceUuid,
        ]);

        return GatewayResponse::pending(
            gatewayTransactionId: $orderId,
            eventType: GatewayResponse::EVENT_PAYMENT_PENDING,
            message: 'Taler order created. Open the GNU Taler wallet to complete payment.',
            rawResponse: array_merge($createResponse->json() ?? [], ['status' => $orderStatusRaw]),
            data: [
                'taler_pay_uri' => $talerPayUri,
                'payment_url'   => $talerPayUri,
                'qr_image'      => $this->qrImageForUri($talerPayUri),
                'qr_text'       => $talerPayUri,
                'order_id'      => $orderId,
                'invoice_uuid'  => $request->invoiceUuid,
                'status'        => $orderStatusRaw['order_status'] ?? null,
            ],
        );
    }

    // -------------------------------------------------------------------------
    // Webhook
    // -------------------------------------------------------------------------

    /**
     * {@inheritdoc}
     *
     * Handles an inbound webhook notification from the Taler Merchant Backend.
     *
     * Taler does not sign webhook payloads with an HMAC secret the way Stripe
     * does. Instead, the recommended security practice is to verify the payment
     * by re-querying the private Merchant API using the order_id received in
     * the webhook body. This prevents replay and spoofing attacks because only
     * the backend — authenticated with the API token — can confirm the status.
     *
     * Expected webhook body (JSON):
     *   { "order_id": "2024-001-XYZ" }
     *
     * Steps:
     *   1. Extract order_id from request body.
     *   2. GET /instances/{id}/private/orders/{order_id} to verify status.
     *   3. If order_status == "paid", parse amount and invoice_uuid.
     *   4. Return GatewayResponse::success() or ::failure() accordingly.
     *
     * @param Request $request Incoming HTTP request from Taler
     *
     * @return GatewayResponse Normalized response for event dispatching
     *
     * @throws WebhookSignatureException never thrown by this driver (verification
     *                                   is done via API re-query, not signature)
     */
    public function handleWebhook(Request $request): GatewayResponse
    {
        if ($configurationFailure = $this->configurationFailureResponse()) {
            return $configurationFailure;
        }

        $orderId = $request->input('order_id');

        if (!$orderId) {
            $this->logError('Webhook received without order_id', [
                'payload' => $request->all(),
            ]);

            return GatewayResponse::failure(
                eventType: GatewayResponse::EVENT_UNKNOWN,
                message: 'Taler webhook missing order_id.',
                rawResponse: $request->all(),
            );
        }

        $instanceId = $this->instanceId();

        $this->logInfo('Webhook received, verifying order', ['order_id' => $orderId]);

        try {
            $response = $this->privateRequest('GET', "instances/{$instanceId}/private/orders/{$orderId}");
        } catch (\Throwable $e) {
            $this->logError('Webhook order verification HTTP error', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);

            return GatewayResponse::failure(
                gatewayTransactionId: $orderId,
                eventType: GatewayResponse::EVENT_PAYMENT_FAILED,
                message: 'Taler webhook verification failed: ' . $e->getMessage(),
                rawResponse: ['error' => $e->getMessage()],
            );
        }

        if (!$response->successful()) {
            $this->logError('Webhook order verification returned non-2xx', [
                'order_id' => $orderId,
                'status'   => $response->status(),
            ]);

            return GatewayResponse::failure(
                gatewayTransactionId: $orderId,
                eventType: GatewayResponse::EVENT_PAYMENT_FAILED,
                message: 'Taler order verification failed: ' . $response->body(),
                rawResponse: $response->json() ?? [],
            );
        }

        $data        = $response->json() ?? [];
        $orderStatus = $data['order_status'] ?? null;

        if ($orderStatus !== 'paid') {
            $this->logInfo('Webhook received for unpaid order, ignoring', [
                'order_id'     => $orderId,
                'order_status' => $orderStatus,
            ]);

            return GatewayResponse::failure(
                gatewayTransactionId: $orderId,
                eventType: GatewayResponse::EVENT_PAYMENT_FAILED,
                message: "Taler order [{$orderId}] is not paid (status: {$orderStatus}).",
                rawResponse: $data,
            );
        }

        // Extract the invoice_uuid that was embedded in the contract terms
        // during order creation. The HandleSuccessfulPayment listener uses this
        // to locate and mark the Fleetbase invoice as paid.
        $contractTerms = $data['contract_terms'] ?? [];
        $metadata      = $contractTerms['metadata'] ?? [];
        $invoiceUuid   = $contractTerms['invoice_uuid'] ?? $metadata['invoice_uuid'] ?? null;

        // Parse the deposit_total amount back to Fleetbase integer cents.
        $depositTotal             = $data['deposit_total'] ?? null;
        [$currency, $amountCents] = $this->fromTalerAmount($depositTotal);

        $this->logInfo('Webhook verified: payment confirmed', [
            'order_id'     => $orderId,
            'invoice_uuid' => $invoiceUuid,
            'amount_cents' => $amountCents,
            'currency'     => $currency,
        ]);

        return GatewayResponse::success(
            gatewayTransactionId: $orderId,
            eventType: GatewayResponse::EVENT_PAYMENT_SUCCEEDED,
            message: 'GNU Taler payment confirmed.',
            amount: $amountCents,
            currency: $currency,
            rawResponse: $data,
            data: [
                'invoice_uuid' => $invoiceUuid,
                'order_id'     => $orderId,
                'metadata'     => $metadata,
                'wired'        => $data['wired'] ?? false,
                'last_payment' => $data['last_payment'] ?? null,
            ],
        );
    }

    // -------------------------------------------------------------------------
    // Refund
    // -------------------------------------------------------------------------

    /**
     * {@inheritdoc}
     *
     * Issues a refund against a previously paid Taler order.
     *
     * Taler refunds are cumulative: each call to the refund endpoint increases
     * the total refunded amount up to the original order total. The backend
     * will reject a refund that exceeds the original amount.
     *
     * Steps:
     *   1. Convert integer cents → Taler amount string.
     *   2. POST to /instances/{id}/private/orders/{order_id}/refund.
     *   3. Return GatewayResponse::success() with EVENT_REFUND_PROCESSED.
     *
     * @param RefundRequest $request Immutable refund request DTO
     *
     * @return GatewayResponse Success or failure response
     */
    public function refund(RefundRequest $request): GatewayResponse
    {
        if ($configurationFailure = $this->configurationFailureResponse(GatewayResponse::EVENT_REFUND_FAILED)) {
            return $configurationFailure;
        }

        $instanceId = $this->instanceId();
        $orderId    = $request->gatewayTransactionId;

        $talerAmount = $this->toTalerAmount($request->amount, $request->currency);

        $payload = [
            'refund' => $talerAmount,
            'reason' => $request->reason ?? 'Customer requested refund',
        ];

        $this->logInfo('Issuing Taler refund', [
            'order_id'     => $orderId,
            'amount'       => $talerAmount,
            'invoice_uuid' => $request->invoiceUuid,
        ]);

        try {
            $response = $this->privateRequest(
                'POST',
                "instances/{$instanceId}/private/orders/{$orderId}/refund",
                $payload
            );
        } catch (\Throwable $e) {
            $this->logError('Refund HTTP error', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);

            return GatewayResponse::failure(
                gatewayTransactionId: $orderId,
                eventType: GatewayResponse::EVENT_REFUND_FAILED,
                message: 'Taler refund failed: ' . $e->getMessage(),
                rawResponse: ['error' => $e->getMessage()],
            );
        }

        if (!$response->successful()) {
            $this->logError('Refund request failed', [
                'order_id' => $orderId,
                'status'   => $response->status(),
                'body'     => $response->body(),
            ]);

            return GatewayResponse::failure(
                gatewayTransactionId: $orderId,
                eventType: GatewayResponse::EVENT_REFUND_FAILED,
                message: 'Taler refund failed: ' . $response->body(),
                rawResponse: $response->json() ?? [],
            );
        }

        $this->logInfo('Taler refund issued successfully', [
            'order_id' => $orderId,
            'amount'   => $talerAmount,
        ]);

        $rawResponse = $response->json() ?? [];
        $refundUri   = $rawResponse['taler_refund_uri'] ?? null;

        return GatewayResponse::success(
            gatewayTransactionId: $orderId,
            eventType: GatewayResponse::EVENT_REFUND_PROCESSED,
            message: 'GNU Taler refund processed.',
            amount: $request->amount,
            currency: $request->currency,
            rawResponse: $rawResponse,
            data: [
                'invoice_uuid'     => $request->invoiceUuid,
                'order_id'         => $orderId,
                'taler_refund_uri' => $refundUri,
                'refund_url'       => $refundUri,
                'refund_amount'    => $talerAmount,
            ],
        );
    }

    // -------------------------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------------------------

    /**
     * Return the trimmed Merchant Backend base URL from config.
     */
    private function backendUrl(): string
    {
        return rtrim($this->config('backend_url', ''), '/');
    }

    /**
     * Return the configured Taler merchant instance ID, defaulting to "default".
     */
    private function instanceId(): string
    {
        return $this->config('instance_id', 'default');
    }

    private function configurationFailureResponse(string $eventType = GatewayResponse::EVENT_PAYMENT_FAILED): ?GatewayResponse
    {
        if (!$this->backendUrl()) {
            return GatewayResponse::failure(
                eventType: $eventType,
                message: 'Taler Merchant Backend URL is not configured.',
            );
        }

        if (!$this->apiToken()) {
            return GatewayResponse::failure(
                eventType: $eventType,
                message: 'Taler API token is not configured.',
            );
        }

        return null;
    }

    private function orderIdForPurchase(PurchaseRequest $request): string
    {
        if (!empty($request->metadata['taler_order_id'])) {
            return $this->sanitizeOrderId($request->metadata['taler_order_id']);
        }

        $source = implode('|', array_filter([
            'ledger',
            $request->invoiceUuid,
            $request->metadata['invoice_public_id'] ?? null,
            $request->amount,
            strtoupper($request->currency),
        ]));

        return 'ledger-' . substr(hash('sha256', $source ?: Str::uuid()->toString()), 0, 32);
    }

    private function sanitizeOrderId(string $orderId): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9_.:-]/', '-', $orderId) ?: Str::uuid()->toString();

        return Str::limit($sanitized, 64, '');
    }

    /**
     * Execute an authenticated HTTP request against the Taler Merchant Backend.
     *
     * All private API endpoints require a Bearer token. In sandbox mode the
     * driver still uses the configured token; the sandbox distinction is
     * handled entirely by the backend_url pointing to a test instance.
     *
     * @param string $method  HTTP method (GET, POST, PATCH, DELETE)
     * @param string $path    Relative API path (no leading slash)
     * @param array  $payload Optional JSON body for POST/PATCH requests
     *
     * @return HttpResponse Laravel HTTP client response
     */
    private function privateRequest(string $method, string $path, array $payload = []): HttpResponse
    {
        $url     = $this->backendUrl() . '/' . ltrim($path, '/');
        $token   = $this->apiToken();
        $pending = Http::withToken($token)
                       ->acceptJson()
                       ->contentType('application/json');

        return match (strtoupper($method)) {
            'POST'   => $pending->post($url, $payload),
            'PATCH'  => $pending->patch($url, $payload),
            'DELETE' => $pending->delete($url, $payload),
            default  => $pending->get($url),
        };
    }

    /**
     * Convert a Fleetbase integer amount (smallest currency unit) to a Taler
     * amount string in the format "CURRENCY:UNITS.FRACTION".
     *
     * Examples:
     *   toTalerAmount(1050, 'USD') → "USD:10.50"
     *   toTalerAmount(100,  'JPY') → "JPY:100.00"
     *   toTalerAmount(0,    'EUR') → "EUR:0.00"
     *
     * @param int    $amountCents Integer amount in smallest currency unit
     * @param string $currency    ISO 4217 currency code
     *
     * @return string Taler amount string
     */
    private function toTalerAmount(int $amountCents, string $currency): string
    {
        $units    = (int) floor($amountCents / 100);
        $fraction = $amountCents % 100;

        return sprintf('%s:%d.%02d', strtoupper($currency), $units, $fraction);
    }

    private function apiToken(): string
    {
        return preg_replace('/^Bearer\s+/i', '', trim((string) $this->config('api_token', '')));
    }

    private function qrImageForUri(string $uri): ?string
    {
        try {
            return DNS2D::getBarcodePNG($uri, 'QRCODE', 8, 8);
        } catch (\Throwable $e) {
            $this->logError('Unable to generate Taler QR code', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Parse a Taler amount string back into a [currency, integer cents] tuple.
     *
     * Returns ['USD', 0] if the string is null, empty, or malformed.
     *
     * Examples:
     *   fromTalerAmount("USD:10.50") → ['USD', 1050]
     *   fromTalerAmount("EUR:0.99")  → ['EUR', 99]
     *   fromTalerAmount(null)        → ['USD', 0]
     *
     * @param string|null $talerAmount Taler amount string
     *
     * @return array{0: string, 1: int} [currency, amountCents]
     */
    private function fromTalerAmount(?string $talerAmount): array
    {
        if (!$talerAmount) {
            return ['USD', 0];
        }

        // Match "CURRENCY:UNITS.FRACTION" — fraction is optional.
        if (!preg_match('/^([A-Z]{2,8}):(\d+)(?:\.(\d{1,2}))?$/', $talerAmount, $m)) {
            $this->logError('Could not parse Taler amount string', ['value' => $talerAmount]);

            return ['USD', 0];
        }

        $currency    = $m[1];
        $units       = (int) $m[2];
        $fractionStr = $m[3] ?? '00';

        // Normalise fraction to exactly 2 digits (pad right if needed).
        $fraction = (int) str_pad($fractionStr, 2, '0');

        return [$currency, ($units * 100) + $fraction];
    }
}
