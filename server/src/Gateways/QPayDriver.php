<?php

namespace Fleetbase\Ledger\Gateways;

use Fleetbase\Ledger\DTO\GatewayResponse;
use Fleetbase\Ledger\DTO\PurchaseRequest;
use Fleetbase\Ledger\DTO\RefundRequest;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * QPayDriver.
 *
 * Payment gateway driver for QPay (Mongolia).
 *
 * QPay is a Mongolian payment gateway that uses an invoice-based flow:
 *   1. Create an invoice on QPay → receive a QPay invoice ID + deep-link URLs
 *   2. Customer pays via QPay app using the deep-link or QR code
 *   3. QPay sends a callback (webhook) to notify of payment completion
 *   4. Optionally poll the payment check endpoint to confirm status
 *
 * Supports:
 *   - Simple invoice creation (no eBarimt)
 *   - eBarimt invoice creation (Mongolian electronic receipt)
 *   - Payment status polling
 *   - Refunds via QPay refund API
 *   - Webhook callback handling
 *   - Sandbox mode via merchant-sandbox.qpay.mn
 *
 * Configuration schema keys:
 *   - username     : QPay merchant username
 *   - password     : QPay merchant password
 *   - invoice_code : QPay invoice code (from QPay merchant dashboard)
 */
class QPayDriver extends AbstractGatewayDriver
{
    /**
     * QPay production API base URL.
     */
    private const HOST_PRODUCTION = 'https://merchant.qpay.mn/v2/';

    /**
     * QPay sandbox API base URL.
     */
    private const HOST_SANDBOX = 'https://merchant-sandbox.qpay.mn/v2/';

    /**
     * The Guzzle HTTP client.
     */
    protected ?Client $client = null;

    /**
     * The current bearer token for authenticated requests.
     */
    protected ?string $accessToken = null;

    /**
     * Classification codes exempt from VAT in Mongolia.
     */
    public static array $zeroTaxClassificationCodes = [
        '2111100', '2111300', '2111500', '2111600',
        '2112100', '2112200', '2112300',
        '2113100', '2113300', '2113500', '2113600', '2113700', '2113800', '2113900',
        '2114100', '2114200', '2114300', '2114400',
        '2115100', '2115200', '2115300', '2115500', '2115600',
        '2115910', '2115920', '2115930', '2115940', '2115990',
        '2116000',
        '2117100', '2117210', '2117290', '2117300', '2117410', '2117490',
        '2117500', '2117600', '2117900',
        '2118000', '2119000',
    ];

    public function getName(): string
    {
        return 'QPay';
    }

    public function getCode(): string
    {
        return 'qpay';
    }

    public function getCapabilities(): array
    {
        return [
            'purchase',
            'refund',
            'webhooks',
            'sandbox',
        ];
    }

    public function getConfigSchema(): array
    {
        return [
            [
                'key'      => 'username',
                'label'    => 'QPay Username',
                'type'     => 'text',
                'required' => true,
                'hint'     => 'Your QPay merchant username.',
            ],
            [
                'key'      => 'password',
                'label'    => 'QPay Password',
                'type'     => 'password',
                'required' => true,
                'hint'     => 'Your QPay merchant password.',
            ],
            [
                'key'      => 'invoice_code',
                'label'    => 'Invoice Code',
                'type'     => 'text',
                'required' => true,
                'hint'     => 'Your QPay invoice code from the merchant dashboard.',
            ],
        ];
    }

    /**
     * {@inheritdoc}
     *
     * Initializes the Guzzle client with QPay credentials.
     */
    public function initialize(array $config, bool $sandbox = false): static
    {
        parent::initialize($config, $sandbox);

        $baseUri = $sandbox ? self::HOST_SANDBOX : self::HOST_PRODUCTION;

        $this->client = new Client([
            'base_uri' => $baseUri,
            'auth'     => [
                $this->config('username'),
                $this->config('password'),
            ],
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'http_errors' => false,
        ]);

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * Creates a QPay invoice and returns the payment deep-link URLs.
     *
     * QPay uses an invoice-based flow. The payment is not immediately confirmed —
     * the response will have status 'pending'. The customer pays via the QPay app
     * using the URLs in $response->data['urls']. Payment confirmation arrives via
     * webhook callback or can be polled via checkPaymentStatus().
     */
    public function purchase(PurchaseRequest $request): GatewayResponse
    {
        try {
            // Authenticate and get bearer token
            $this->authenticate();

            $invoiceCode     = $this->config('invoice_code');
            $callbackUrl     = $request->returnUrl ?? $this->buildCallbackUrl($request);
            $senderInvoiceNo = $request->invoiceUuid
                ? Str::substr($request->invoiceUuid, 0, 32)
                : Str::uuid()->toString();

            $params = [
                'invoice_code'          => $invoiceCode,
                'sender_invoice_no'     => $senderInvoiceNo,
                'invoice_receiver_code' => 'terminal',
                'invoice_description'   => $request->description,
                'amount'                => $request->amount,
                'callback_url'          => $callbackUrl,
            ];

            $response = $this->post('invoice', $params);

            if (!$response || !isset($response->invoice_id)) {
                $this->logError('QPay invoice creation failed — no invoice_id in response', [
                    'response' => (array) $response,
                ]);

                return GatewayResponse::failure(
                    eventType: GatewayResponse::EVENT_PAYMENT_FAILED,
                    message: 'QPay invoice creation failed.',
                    rawResponse: (array) $response,
                );
            }

            $this->logInfo('QPay invoice created', [
                'invoice_id' => $response->invoice_id,
                'amount'     => $request->amount,
            ]);

            // Build payment URL list for the frontend
            $urls = [];
            if (isset($response->urls) && is_array($response->urls)) {
                foreach ($response->urls as $urlEntry) {
                    $urls[] = [
                        'name'        => $urlEntry->name ?? '',
                        'description' => $urlEntry->description ?? '',
                        'logo'        => $urlEntry->logo ?? '',
                        'link'        => $urlEntry->link ?? '',
                    ];
                }
            }

            return GatewayResponse::pending(
                gatewayTransactionId: $response->invoice_id,
                eventType: GatewayResponse::EVENT_PAYMENT_PENDING,
                message: 'QPay invoice created. Awaiting customer payment.',
                rawResponse: (array) $response,
                data: [
                    'invoice_id'  => $response->invoice_id,
                    'qr_image'    => $response->qr_image ?? null,
                    'qr_text'     => $response->qr_text ?? null,
                    'urls'        => $urls,
                ],
            );
        } catch (\Exception $e) {
            $this->logError('QPay purchase exception', ['error' => $e->getMessage()]);

            return GatewayResponse::failure(
                eventType: GatewayResponse::EVENT_PAYMENT_FAILED,
                message: $e->getMessage(),
                rawResponse: ['error' => $e->getMessage()],
            );
        }
    }

    /**
     * {@inheritdoc}
     *
     * Refunds a QPay payment by invoice ID.
     */
    public function refund(RefundRequest $request): GatewayResponse
    {
        try {
            $this->authenticate();

            $params = [
                'callback_url' => url('/ledger/webhooks/qpay'),
            ];

            $response = $this->delete(
                'payment/refund/' . $request->gatewayTransactionId,
                $params
            );

            $successful = $response && !isset($response->error);

            $this->logInfo('QPay refund attempted', [
                'invoice_id' => $request->gatewayTransactionId,
                'successful' => $successful,
            ]);

            return new GatewayResponse(
                successful: $successful,
                gatewayTransactionId: $request->gatewayTransactionId,
                status: $successful ? GatewayResponse::STATUS_REFUNDED : GatewayResponse::STATUS_FAILED,
                eventType: $successful ? GatewayResponse::EVENT_REFUND_PROCESSED : GatewayResponse::EVENT_REFUND_FAILED,
                message: $successful ? 'Refund processed.' : 'Refund failed.',
                rawResponse: (array) $response,
            );
        } catch (\Exception $e) {
            $this->logError('QPay refund exception', [
                'error'      => $e->getMessage(),
                'invoice_id' => $request->gatewayTransactionId,
            ]);

            return GatewayResponse::failure(
                eventType: GatewayResponse::EVENT_REFUND_FAILED,
                message: $e->getMessage(),
                rawResponse: ['error' => $e->getMessage()],
            );
        }
    }

    /**
     * {@inheritdoc}
     *
     * Handles QPay payment callback (webhook).
     *
     * QPay sends a POST callback with a payment_id when a payment is completed.
     * We verify the payment by calling the payment check endpoint.
     *
     * QPay does not use HMAC signature verification — instead, we verify
     * by re-querying the QPay API with the payment ID.
     */
    public function handleWebhook(Request $request): GatewayResponse
    {
        $paymentId = $request->input('payment_id');
        $invoiceId = $request->input('qpay_payment_id') ?? $request->input('invoice_id');

        if (!$paymentId && !$invoiceId) {
            return GatewayResponse::failure(
                eventType: GatewayResponse::EVENT_UNKNOWN,
                message: 'QPay webhook received without payment_id or invoice_id.',
                rawResponse: $request->all(),
            );
        }

        try {
            $this->authenticate();

            // Verify payment by querying QPay API
            $referenceId = $invoiceId ?? $paymentId;
            $checkResult = $this->checkPaymentStatus($referenceId);

            if (!$checkResult) {
                return GatewayResponse::failure(
                    gatewayTransactionId: $referenceId,
                    eventType: GatewayResponse::EVENT_PAYMENT_FAILED,
                    message: 'QPay payment verification failed.',
                    rawResponse: $request->all(),
                );
            }

            $rows    = $checkResult->rows ?? [];
            $payment = count($rows) > 0 ? $rows[0] : null;

            if (!$payment) {
                return GatewayResponse::failure(
                    gatewayTransactionId: $referenceId,
                    eventType: GatewayResponse::EVENT_PAYMENT_FAILED,
                    message: 'QPay payment not found.',
                    rawResponse: (array) $checkResult,
                );
            }

            $paymentStatus = strtolower($payment->payment_status ?? '');
            $isSuccessful  = in_array($paymentStatus, ['paid', 'success', 'complete'], true);

            $this->logInfo('QPay webhook processed', [
                'invoice_id'     => $referenceId,
                'payment_status' => $paymentStatus,
                'successful'     => $isSuccessful,
            ]);

            return new GatewayResponse(
                successful: $isSuccessful,
                gatewayTransactionId: $referenceId,
                status: $isSuccessful ? GatewayResponse::STATUS_SUCCEEDED : GatewayResponse::STATUS_FAILED,
                eventType: $isSuccessful ? GatewayResponse::EVENT_PAYMENT_SUCCEEDED : GatewayResponse::EVENT_PAYMENT_FAILED,
                message: $isSuccessful ? 'QPay payment confirmed.' : 'QPay payment not confirmed.',
                rawResponse: array_merge($request->all(), ['check_result' => (array) $checkResult]),
                data: [
                    'invoice_id'     => $referenceId,
                    'payment_id'     => $payment->payment_id ?? null,
                    'payment_status' => $paymentStatus,
                ],
            );
        } catch (\Exception $e) {
            $this->logError('QPay webhook exception', ['error' => $e->getMessage()]);

            return GatewayResponse::failure(
                eventType: GatewayResponse::EVENT_PAYMENT_FAILED,
                message: $e->getMessage(),
                rawResponse: array_merge($request->all(), ['error' => $e->getMessage()]),
            );
        }
    }

    // -------------------------------------------------------------------------
    // QPay API Methods
    // -------------------------------------------------------------------------

    /**
     * Authenticate with QPay and set the bearer token on the client.
     *
     * @throws \RuntimeException if authentication fails
     */
    public function authenticate(): void
    {
        if ($this->accessToken) {
            return; // Already authenticated
        }

        $response = $this->post('auth/token', []);

        if (!$response || !isset($response->access_token)) {
            throw new \RuntimeException('QPay authentication failed: no access_token in response.');
        }

        $this->accessToken = $response->access_token;

        // Rebuild client with bearer token
        $baseUri      = $this->sandbox ? self::HOST_SANDBOX : self::HOST_PRODUCTION;
        $this->client = new Client([
            'base_uri' => $baseUri,
            'headers'  => [
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $this->accessToken,
            ],
            'http_errors' => false,
        ]);
    }

    /**
     * Check the payment status for a QPay invoice.
     *
     * @param string $invoiceId The QPay invoice ID
     *
     * @return object|null The payment check response
     */
    public function checkPaymentStatus(string $invoiceId): ?object
    {
        $params = [
            'object_type' => 'INVOICE',
            'object_id'   => $invoiceId,
        ];

        return $this->post('payment/check', $params);
    }

    /**
     * Create a QPay eBarimt invoice (Mongolian electronic receipt).
     *
     * @param int    $amount              Amount in smallest currency unit
     * @param string $invoiceCode         QPay invoice code
     * @param string $senderInvoiceNo     Unique sender invoice number
     * @param array  $lines               Invoice line items with classification codes
     * @param array  $invoiceReceiverData Receiver details
     * @param string $taxType             Tax type code (default: '1')
     * @param string $callbackUrl         Callback URL for payment notifications
     */
    public function createEbarimtInvoice(
        int $amount,
        string $invoiceCode,
        string $senderInvoiceNo,
        array $lines,
        array $invoiceReceiverData = [],
        string $taxType = '1',
        string $callbackUrl = '',
    ): GatewayResponse {
        try {
            $this->authenticate();

            $params = array_filter([
                'invoice_code'          => $invoiceCode,
                'sender_invoice_no'     => $senderInvoiceNo,
                'invoice_receiver_code' => 'terminal',
                'invoice_receiver_data' => $invoiceReceiverData,
                'invoice_description'   => 'eBarimt Invoice',
                'amount'                => $amount,
                'tax_type'              => $taxType,
                'lines'                 => $lines,
                'callback_url'          => $callbackUrl ?: url('/ledger/webhooks/qpay'),
            ]);

            $response = $this->post('invoice', $params);

            if (!$response || !isset($response->invoice_id)) {
                return GatewayResponse::failure(
                    eventType: GatewayResponse::EVENT_PAYMENT_FAILED,
                    message: 'QPay eBarimt invoice creation failed.',
                    rawResponse: (array) $response,
                );
            }

            return GatewayResponse::pending(
                gatewayTransactionId: $response->invoice_id,
                message: 'QPay eBarimt invoice created.',
                rawResponse: (array) $response,
                data: [
                    'invoice_id' => $response->invoice_id,
                    'qr_image'   => $response->qr_image ?? null,
                    'qr_text'    => $response->qr_text ?? null,
                    'urls'       => $response->urls ?? [],
                ],
            );
        } catch (\Exception $e) {
            return GatewayResponse::failure(
                eventType: GatewayResponse::EVENT_PAYMENT_FAILED,
                message: $e->getMessage(),
                rawResponse: ['error' => $e->getMessage()],
            );
        }
    }

    // -------------------------------------------------------------------------
    // HTTP Helpers
    // -------------------------------------------------------------------------

    /**
     * Make a POST request to the QPay API.
     *
     * @param string $path   API endpoint path
     * @param array  $params Request body parameters
     *
     * @return object|null Decoded JSON response
     */
    protected function post(string $path, array $params): ?object
    {
        try {
            $response = $this->client->post($path, ['json' => $params]);

            return json_decode($response->getBody()->getContents());
        } catch (GuzzleException $e) {
            $this->logError("POST {$path} failed", ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Make a GET request to the QPay API.
     *
     * @param string $path API endpoint path
     *
     * @return object|null Decoded JSON response
     */
    protected function get(string $path): ?object
    {
        try {
            $response = $this->client->get($path);

            return json_decode($response->getBody()->getContents());
        } catch (GuzzleException $e) {
            $this->logError("GET {$path} failed", ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Make a DELETE request to the QPay API.
     *
     * @param string $path   API endpoint path
     * @param array  $params Request body parameters
     *
     * @return object|null Decoded JSON response
     */
    protected function delete(string $path, array $params = []): ?object
    {
        try {
            $response = $this->client->delete($path, ['json' => $params]);

            return json_decode($response->getBody()->getContents());
        } catch (GuzzleException $e) {
            $this->logError("DELETE {$path} failed", ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Build the default callback URL for QPay payment notifications.
     */
    private function buildCallbackUrl(PurchaseRequest $request): string
    {
        $base = url('/ledger/webhooks/qpay');

        if ($request->invoiceUuid) {
            return $base . '?invoice_uuid=' . $request->invoiceUuid;
        }

        return $base;
    }
}
