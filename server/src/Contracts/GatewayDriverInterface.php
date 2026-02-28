<?php

namespace Fleetbase\Ledger\Contracts;

use Fleetbase\Ledger\DTO\GatewayResponse;
use Fleetbase\Ledger\DTO\PurchaseRequest;
use Fleetbase\Ledger\DTO\RefundRequest;
use Illuminate\Http\Request;

/**
 * GatewayDriverInterface
 *
 * This interface defines the strict contract that every payment gateway driver
 * in the Ledger extension must implement. It covers the full payment lifecycle:
 * charging, refunding, webhook handling, and optional tokenization.
 *
 * To add a new payment gateway, create a class that extends AbstractGatewayDriver
 * and implements this interface. No other core files need to be modified.
 *
 * @package Fleetbase\Ledger\Contracts
 */
interface GatewayDriverInterface
{
    /**
     * Return the human-readable display name of this gateway.
     * Used in the UI, logs, and error messages.
     *
     * @return string e.g. 'Stripe', 'QPay', 'Cash on Delivery'
     */
    public function getName(): string;

    /**
     * Return the machine-readable driver code for this gateway.
     * Must be unique, lowercase, and URL-safe.
     *
     * @return string e.g. 'stripe', 'qpay', 'cash'
     */
    public function getCode(): string;

    /**
     * Return an array of capability strings that this driver supports.
     *
     * Possible values:
     *   - 'purchase'      : Can initiate a charge
     *   - 'refund'        : Can refund a transaction
     *   - 'tokenization'  : Can store a payment method for future use
     *   - 'webhooks'      : Sends asynchronous webhook events
     *   - 'sandbox'       : Supports a test/sandbox mode
     *   - 'setup_intent'  : Supports Stripe-style setup intents (save card without charge)
     *   - 'recurring'     : Supports recurring/subscription billing
     *
     * @return string[]
     */
    public function getCapabilities(): array;

    /**
     * Return the configuration schema for this gateway.
     *
     * This schema is used by the frontend to dynamically render the gateway
     * settings form. Each entry is an associative array with the following keys:
     *   - 'key'      : The config key (e.g., 'secret_key')
     *   - 'label'    : Human-readable label (e.g., 'Secret Key')
     *   - 'type'     : Input type: 'text', 'password', 'boolean', 'select'
     *   - 'required' : Whether the field is required (bool)
     *   - 'options'  : (optional) Array of options for 'select' type
     *   - 'hint'     : (optional) Helper text shown below the field
     *
     * @return array<int, array<string, mixed>>
     */
    public function getConfigSchema(): array;

    /**
     * Initialize the driver with the persisted Gateway model configuration.
     *
     * Called automatically by the PaymentGatewayManager after resolving the driver.
     * The config array contains the decrypted key-value pairs from the Gateway model.
     *
     * @param array $config  Decrypted configuration from the Gateway model
     * @param bool  $sandbox Whether to operate in sandbox/test mode
     *
     * @return static
     */
    public function initialize(array $config, bool $sandbox = false): static;

    /**
     * Initiate a payment charge.
     *
     * @param PurchaseRequest $request The purchase request DTO
     *
     * @return GatewayResponse A standardized response object
     */
    public function purchase(PurchaseRequest $request): GatewayResponse;

    /**
     * Refund a previously captured transaction.
     *
     * @param RefundRequest $request The refund request DTO
     *
     * @return GatewayResponse A standardized response object
     */
    public function refund(RefundRequest $request): GatewayResponse;

    /**
     * Verify and parse an incoming webhook request from this gateway.
     *
     * This method MUST verify the webhook signature before processing.
     * It returns a normalized GatewayResponse containing the event type
     * and relevant data. The WebhookController handles idempotency and
     * event dispatching after this method returns.
     *
     * @param Request $request The incoming HTTP request
     *
     * @return GatewayResponse A standardized response with event type and data
     *
     * @throws \Fleetbase\Ledger\Exceptions\WebhookSignatureException if signature is invalid
     */
    public function handleWebhook(Request $request): GatewayResponse;

    /**
     * Create a stored payment method (tokenize a card or bank account).
     *
     * Returns a GatewayResponse containing the token reference in
     * $response->gatewayTransactionId. This token can be stored and
     * used in future PurchaseRequest::$paymentMethodToken.
     *
     * This method is optional. The default implementation in AbstractGatewayDriver
     * throws a RuntimeException. Only implement if 'tokenization' is in getCapabilities().
     *
     * @param array $data Gateway-specific tokenization data
     *
     * @return GatewayResponse
     */
    public function createPaymentMethod(array $data): GatewayResponse;
}
