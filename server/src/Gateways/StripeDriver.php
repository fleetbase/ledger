<?php

namespace Fleetbase\Ledger\Gateways;

use Fleetbase\Ledger\DTO\GatewayResponse;
use Fleetbase\Ledger\DTO\PurchaseRequest;
use Fleetbase\Ledger\DTO\RefundRequest;
use Fleetbase\Ledger\Exceptions\WebhookSignatureException;
use Illuminate\Http\Request;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * StripeDriver.
 *
 * Payment gateway driver for Stripe.
 *
 * Supports:
 *   - One-off charges via PaymentIntent
 *   - Refunds via Stripe Refund API
 *   - Webhook signature verification via Stripe-Signature header
 *   - Payment method tokenization via SetupIntent
 *   - Sandbox mode via Stripe test keys
 *
 * Configuration schema keys:
 *   - publishable_key : Stripe publishable key (pk_live_xxx or pk_test_xxx)
 *   - secret_key      : Stripe secret key (sk_live_xxx or sk_test_xxx)
 *   - webhook_secret  : Stripe webhook signing secret (whsec_xxx)
 */
class StripeDriver extends AbstractGatewayDriver
{
    /**
     * The initialized Stripe client.
     */
    protected ?StripeClient $client = null;

    public function getName(): string
    {
        return 'Stripe';
    }

    public function getCode(): string
    {
        return 'stripe';
    }

    public function getCapabilities(): array
    {
        return [
            'purchase',
            'refund',
            'tokenization',
            'setup_intent',
            'checkout_session',
            'webhooks',
            'sandbox',
            'recurring',
        ];
    }

    public function getConfigSchema(): array
    {
        return [
            [
                'key'      => 'publishable_key',
                'label'    => 'Publishable Key',
                'type'     => 'text',
                'required' => true,
                'hint'     => 'Starts with pk_live_ or pk_test_. Used on the frontend.',
            ],
            [
                'key'      => 'secret_key',
                'label'    => 'Secret Key',
                'type'     => 'password',
                'required' => true,
                'hint'     => 'Starts with sk_live_ or sk_test_. Never expose this publicly.',
            ],
            [
                'key'      => 'webhook_secret',
                'label'    => 'Webhook Signing Secret',
                'type'     => 'password',
                'required' => false,
                'hint'     => 'Starts with whsec_. Found in your Stripe Dashboard under Webhooks.',
            ],
        ];
    }

    /**
     * {@inheritdoc}
     *
     * Initializes the Stripe SDK with the configured secret key.
     */
    public function initialize(array $config, bool $sandbox = false): static
    {
        parent::initialize($config, $sandbox);

        $secretKey = $this->config('secret_key');
        if ($secretKey) {
            $this->client = new StripeClient($secretKey);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * Creates a Stripe PaymentIntent and confirms it.
     *
     * For client-side confirmation flows (e.g., Stripe Elements), the PaymentIntent
     * is created and returned with status 'requires_confirmation'. The frontend
     * then calls stripe.confirmPayment() using the client_secret from $response->data.
     *
     * For server-side confirmation (token billing), the PaymentIntent is confirmed
     * immediately using the stored payment method token.
     */
    public function purchase(PurchaseRequest $request): GatewayResponse
    {
        try {
            $params = [
                'amount'      => $request->amount,
                'currency'    => strtolower($request->currency),
                'description' => $request->description,
                'metadata'    => array_merge($request->metadata, array_filter([
                    'invoice_uuid' => $request->invoiceUuid,
                    'order_uuid'   => $request->orderUuid,
                ])),
            ];

            // If a payment method token is provided, confirm immediately (server-side)
            if ($request->paymentMethodToken) {
                $params['payment_method']           = $request->paymentMethodToken;
                $params['confirm']                  = true;
                $params['return_url']               = $request->returnUrl ?? url('/');
                $params['off_session']              = true;
                $params['error_on_requires_action'] = true;
            }

            // Attach customer if provided
            if ($request->customerId) {
                $params['customer'] = $request->customerId;
            }

            $paymentIntent = $this->client->paymentIntents->create($params);

            // Determine status
            $status    = $paymentIntent->status === 'succeeded'
                ? GatewayResponse::STATUS_SUCCEEDED
                : GatewayResponse::STATUS_PENDING;
            $eventType = $paymentIntent->status === 'succeeded'
                ? GatewayResponse::EVENT_PAYMENT_SUCCEEDED
                : GatewayResponse::EVENT_PAYMENT_PENDING;

            $this->logInfo('PaymentIntent created', [
                'id'     => $paymentIntent->id,
                'status' => $paymentIntent->status,
                'amount' => $request->amount,
            ]);

            return new GatewayResponse(
                successful: true,
                gatewayTransactionId: $paymentIntent->id,
                status: $status,
                eventType: $eventType,
                message: 'PaymentIntent created successfully.',
                amount: $paymentIntent->amount,
                currency: strtoupper($paymentIntent->currency),
                rawResponse: $paymentIntent->toArray(),
                data: [
                    'client_secret'     => $paymentIntent->client_secret,
                    'payment_intent_id' => $paymentIntent->id,
                    'status'            => $paymentIntent->status,
                    'publishable_key'   => $this->config('publishable_key'),
                ],
            );
        } catch (ApiErrorException $e) {
            $this->logError('PaymentIntent creation failed', [
                'error'   => $e->getMessage(),
                'code'    => $e->getStripeCode(),
                'amount'  => $request->amount,
            ]);

            return GatewayResponse::failure(
                eventType: GatewayResponse::EVENT_PAYMENT_FAILED,
                message: $e->getMessage(),
                errorCode: $e->getStripeCode(),
                rawResponse: ['error' => $e->getMessage()],
            );
        }
    }

    /**
     * {@inheritdoc}
     *
     * Refunds a Stripe charge or PaymentIntent.
     */
    public function refund(RefundRequest $request): GatewayResponse
    {
        try {
            $params = [
                'amount' => $request->amount,
            ];

            // Stripe accepts either a charge ID (ch_xxx) or a PaymentIntent ID (pi_xxx)
            if (str_starts_with($request->gatewayTransactionId, 'pi_')) {
                $params['payment_intent'] = $request->gatewayTransactionId;
            } else {
                $params['charge'] = $request->gatewayTransactionId;
            }

            if ($request->reason) {
                $params['reason'] = $request->reason;
            }

            if (!empty($request->metadata)) {
                $params['metadata'] = $request->metadata;
            }

            $refund = $this->client->refunds->create($params);

            $this->logInfo('Refund created', [
                'id'                     => $refund->id,
                'status'                 => $refund->status,
                'gateway_transaction_id' => $request->gatewayTransactionId,
                'amount'                 => $request->amount,
            ]);

            $successful = in_array($refund->status, ['succeeded', 'pending'], true);

            return new GatewayResponse(
                successful: $successful,
                gatewayTransactionId: $refund->id,
                status: $successful ? GatewayResponse::STATUS_REFUNDED : GatewayResponse::STATUS_FAILED,
                eventType: $successful ? GatewayResponse::EVENT_REFUND_PROCESSED : GatewayResponse::EVENT_REFUND_FAILED,
                message: $successful ? 'Refund processed successfully.' : 'Refund failed.',
                amount: $refund->amount,
                currency: strtoupper($refund->currency),
                rawResponse: $refund->toArray(),
            );
        } catch (ApiErrorException $e) {
            $this->logError('Refund failed', [
                'error'                  => $e->getMessage(),
                'gateway_transaction_id' => $request->gatewayTransactionId,
            ]);

            return GatewayResponse::failure(
                eventType: GatewayResponse::EVENT_REFUND_FAILED,
                message: $e->getMessage(),
                errorCode: $e->getStripeCode(),
                rawResponse: ['error' => $e->getMessage()],
            );
        }
    }

    /**
     * {@inheritdoc}
     *
     * Verifies the Stripe-Signature header and parses the webhook event.
     * Maps Stripe event types to normalized GatewayResponse event types.
     *
     * @throws WebhookSignatureException if the signature is invalid
     */
    public function handleWebhook(Request $request): GatewayResponse
    {
        $webhookSecret = $this->config('webhook_secret');
        $payload       = $request->getContent();
        $sigHeader     = $request->header('Stripe-Signature');

        // Verify signature if a webhook secret is configured
        if ($webhookSecret) {
            try {
                $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
            } catch (SignatureVerificationException $e) {
                throw new WebhookSignatureException('stripe', $e->getMessage());
            }
        } else {
            // No webhook secret configured — parse without verification (not recommended for production)
            $this->logError('Webhook received without signature verification. Configure webhook_secret.');
            $event = \Stripe\Event::constructFrom(json_decode($payload, true) ?? []);
        }

        // Extract the primary object from the event
        $object = $event->data->object;

        // Normalize the Stripe event type to our standard event types
        [$normalizedEvent, $status] = $this->normalizeStripeEvent($event->type, $object);

        $gatewayTransactionId = $object->id ?? $event->id;
        $amount               = $object->amount ?? $object->amount_received ?? null;
        $currency             = isset($object->currency) ? strtoupper($object->currency) : null;

        $this->logInfo('Webhook received', [
            'stripe_event'     => $event->type,
            'normalized_event' => $normalizedEvent,
            'object_id'        => $gatewayTransactionId,
        ]);

        return new GatewayResponse(
            successful: in_array($normalizedEvent, [
                GatewayResponse::EVENT_PAYMENT_SUCCEEDED,
                GatewayResponse::EVENT_REFUND_PROCESSED,
                GatewayResponse::EVENT_SETUP_SUCCEEDED,
            ], true),
            gatewayTransactionId: $gatewayTransactionId,
            status: $status,
            eventType: $normalizedEvent,
            message: "Stripe event: {$event->type}",
            amount: $amount,
            currency: $currency,
            rawResponse: json_decode($payload, true),
            data: [
                'stripe_event_id'   => $event->id,
                'stripe_event_type' => $event->type,
                'object'            => $object->toArray(),
            ],
        );
    }

    /**
     * {@inheritdoc}
     *
     * Creates a Stripe SetupIntent for saving a payment method without charging.
     * Returns the SetupIntent client_secret in $response->data for frontend confirmation.
     */
    public function createPaymentMethod(array $data): GatewayResponse
    {
        try {
            $params = array_filter([
                'customer'             => $data['customer_id'] ?? null,
                'payment_method_types' => ['card'],
                'usage'                => 'off_session',
            ]);

            $setupIntent = $this->client->setupIntents->create($params);

            $this->logInfo('SetupIntent created', ['id' => $setupIntent->id]);

            return GatewayResponse::success(
                gatewayTransactionId: $setupIntent->id,
                eventType: GatewayResponse::EVENT_SETUP_SUCCEEDED,
                message: 'SetupIntent created. Use client_secret on the frontend.',
                rawResponse: $setupIntent->toArray(),
                data: [
                    'client_secret'   => $setupIntent->client_secret,
                    'setup_intent_id' => $setupIntent->id,
                    'publishable_key' => $this->config('publishable_key'),
                ],
            );
        } catch (ApiErrorException $e) {
            $this->logError('SetupIntent creation failed', ['error' => $e->getMessage()]);

            return GatewayResponse::failure(
                eventType: GatewayResponse::EVENT_PAYMENT_FAILED,
                message: $e->getMessage(),
                errorCode: $e->getStripeCode(),
                rawResponse: ['error' => $e->getMessage()],
            );
        }
    }

    /**
     * Create a Stripe Checkout Session for hosted, redirect-based payment.
     *
     * Uses inline price_data so no Products or Prices need to be pre-created in Stripe.
     * Returns a pending GatewayResponse with data.checkout_url set.
     * The caller should return checkout_url to the frontend and let it redirect.
     *
     * @param PurchaseRequest $request    The purchase request DTO
     * @param string          $successUrl URL Stripe redirects to on successful payment
     * @param string          $cancelUrl  URL Stripe redirects to if the customer cancels
     */
    public function createCheckoutSession(PurchaseRequest $request, string $successUrl, string $cancelUrl): GatewayResponse
    {
        try {
            $params = [
                'mode'        => 'payment',
                'success_url' => $successUrl,
                'cancel_url'  => $cancelUrl,
                'line_items'  => [
                    [
                        'quantity'   => 1,
                        'price_data' => [
                            'currency'     => strtolower($request->currency),
                            'unit_amount'  => $request->amount,
                            'product_data' => [
                                'name' => $request->description,
                            ],
                        ],
                    ],
                ],
                'payment_intent_data' => [
                    'metadata' => array_merge($request->metadata, array_filter([
                        'invoice_uuid' => $request->invoiceUuid,
                        'order_uuid'   => $request->orderUuid,
                    ])),
                ],
            ];

            if ($request->customerEmail) {
                $params['customer_email'] = $request->customerEmail;
            }

            $session = $this->client->checkout->sessions->create($params);

            $this->logInfo('Checkout Session created', [
                'id'     => $session->id,
                'amount' => $request->amount,
            ]);

            return GatewayResponse::pending(
                gatewayTransactionId: $session->id,
                eventType: GatewayResponse::EVENT_PAYMENT_PENDING,
                message: 'Stripe Checkout Session created. Redirect customer to checkout_url.',
                rawResponse: $session->toArray(),
                data: [
                    'checkout_url'        => $session->url,
                    'checkout_session_id' => $session->id,
                    'publishable_key'     => $this->config('publishable_key'),
                ],
            );
        } catch (ApiErrorException $e) {
            $this->logError('Checkout Session creation failed', [
                'error' => $e->getMessage(),
                'code'  => $e->getStripeCode(),
            ]);

            return GatewayResponse::failure(
                eventType: GatewayResponse::EVENT_PAYMENT_FAILED,
                message: $e->getMessage(),
                errorCode: $e->getStripeCode(),
                rawResponse: ['error' => $e->getMessage()],
            );
        }
    }

    // -------------------------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------------------------

    /**
     * Map a Stripe event type to a normalized event type and status.
     *
     * @param string $stripeEventType The raw Stripe event type (e.g., 'payment_intent.succeeded')
     * @param mixed  $object          The Stripe event data object
     *
     * @return array{0: string, 1: string} [normalizedEventType, status]
     */
    private function normalizeStripeEvent(string $stripeEventType, mixed $object): array
    {
        return match ($stripeEventType) {
            'payment_intent.succeeded'                => [GatewayResponse::EVENT_PAYMENT_SUCCEEDED, GatewayResponse::STATUS_SUCCEEDED],
            'payment_intent.payment_failed'           => [GatewayResponse::EVENT_PAYMENT_FAILED,    GatewayResponse::STATUS_FAILED],
            'payment_intent.created',
            'payment_intent.processing'               => [GatewayResponse::EVENT_PAYMENT_PENDING,   GatewayResponse::STATUS_PENDING],
            // checkout.session.completed fires when the customer completes a Checkout Session.
            // The payment_intent nested inside the session is the authoritative transaction ID.
            'checkout.session.completed'              => [GatewayResponse::EVENT_PAYMENT_SUCCEEDED, GatewayResponse::STATUS_SUCCEEDED],
            'checkout.session.expired'                => [GatewayResponse::EVENT_PAYMENT_FAILED,    GatewayResponse::STATUS_FAILED],
            'charge.refunded'                         => [GatewayResponse::EVENT_REFUND_PROCESSED,  GatewayResponse::STATUS_REFUNDED],
            'charge.refund.updated'                   => $this->resolveRefundUpdate($object),
            'setup_intent.succeeded'                  => [GatewayResponse::EVENT_SETUP_SUCCEEDED,   GatewayResponse::STATUS_SUCCEEDED],
            'charge.dispute.created'                  => [GatewayResponse::EVENT_CHARGEBACK,        GatewayResponse::STATUS_FAILED],
            default                                   => [GatewayResponse::EVENT_UNKNOWN,           GatewayResponse::STATUS_PENDING],
        };
    }

    /**
     * Resolve the normalized event for a refund update based on its status.
     *
     * @param mixed $object The Stripe refund object
     *
     * @return array{0: string, 1: string}
     */
    private function resolveRefundUpdate(mixed $object): array
    {
        return match ($object->status ?? '') {
            'succeeded' => [GatewayResponse::EVENT_REFUND_PROCESSED, GatewayResponse::STATUS_REFUNDED],
            'failed'    => [GatewayResponse::EVENT_REFUND_FAILED,    GatewayResponse::STATUS_FAILED],
            default     => [GatewayResponse::EVENT_UNKNOWN,          GatewayResponse::STATUS_PENDING],
        };
    }
}
