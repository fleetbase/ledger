<?php

namespace Fleetbase\Ledger\Services;

use Fleetbase\Ledger\DTO\GatewayResponse;
use Fleetbase\Ledger\DTO\PurchaseRequest;
use Fleetbase\Ledger\DTO\RefundRequest;
use Fleetbase\Ledger\Events\PaymentFailed;
use Fleetbase\Ledger\Events\PaymentSucceeded;
use Fleetbase\Ledger\Events\RefundProcessed;
use Fleetbase\Ledger\Models\Gateway;
use Fleetbase\Ledger\Models\GatewayTransaction;
use Fleetbase\Ledger\PaymentGatewayManager;
use Illuminate\Support\Facades\Log;

/**
 * PaymentService
 *
 * Orchestrates the full payment lifecycle for the Ledger extension.
 *
 * This service is the single entry point for all payment operations.
 * It coordinates between the PaymentGatewayManager (driver resolution),
 * GatewayTransaction (persistence), and the event system (notifications).
 *
 * Usage:
 *   $service = app(PaymentService::class);
 *
 *   // Charge a customer
 *   $response = $service->charge($gatewayUuid, $purchaseRequest);
 *
 *   // Refund a transaction
 *   $response = $service->refund($gatewayUuid, $refundRequest);
 *
 *   // Tokenize a payment method
 *   $response = $service->createPaymentMethod($gatewayUuid, $data);
 *
 * @package Fleetbase\Ledger\Services
 */
class PaymentService
{
    public function __construct(
        protected PaymentGatewayManager $gatewayManager,
    ) {
    }

    /**
     * Initiate a payment charge through the specified gateway.
     *
     * This method:
     *   1. Resolves and initializes the gateway driver
     *   2. Calls driver->purchase()
     *   3. Persists the GatewayTransaction record
     *   4. Dispatches PaymentSucceeded or PaymentFailed event
     *
     * @param string          $gatewayIdentifier UUID, public_id, or driver code
     * @param PurchaseRequest $request           The purchase request DTO
     *
     * @return GatewayResponse
     */
    public function charge(string $gatewayIdentifier, PurchaseRequest $request): GatewayResponse
    {
        $gateway = $this->resolveGatewayModel($gatewayIdentifier);
        $driver  = $this->gatewayManager->driver($gateway->driver)
                       ->initialize($gateway->decryptedConfig(), $gateway->is_sandbox);

        $response = $driver->purchase($request);

        $gatewayTransaction = $this->persistTransaction(
            gateway:   $gateway,
            response:  $response,
            type:      'purchase',
            invoiceUuid: $request->invoiceUuid,
        );

        // Dispatch event for immediate successes (e.g., Cash driver, confirmed Stripe PaymentIntent)
        if ($response->isSuccessful() && $response->status === GatewayResponse::STATUS_SUCCEEDED) {
            PaymentSucceeded::dispatch($response, $gateway, $gatewayTransaction);
        } elseif ($response->isFailed()) {
            PaymentFailed::dispatch($response, $gateway, $gatewayTransaction);
        }
        // Pending responses (e.g., QPay, unconfirmed Stripe) are handled via webhook

        return $response;
    }

    /**
     * Refund a previously captured transaction.
     *
     * @param string        $gatewayIdentifier UUID, public_id, or driver code
     * @param RefundRequest $request           The refund request DTO
     *
     * @return GatewayResponse
     */
    public function refund(string $gatewayIdentifier, RefundRequest $request): GatewayResponse
    {
        $gateway = $this->resolveGatewayModel($gatewayIdentifier);
        $driver  = $this->gatewayManager->driver($gateway->driver)
                       ->initialize($gateway->decryptedConfig(), $gateway->is_sandbox);

        $response = $driver->refund($request);

        $gatewayTransaction = $this->persistTransaction(
            gateway:   $gateway,
            response:  $response,
            type:      'refund',
            invoiceUuid: $request->invoiceUuid,
        );

        if ($response->isSuccessful()) {
            RefundProcessed::dispatch($response, $gateway, $gatewayTransaction);
        }

        return $response;
    }

    /**
     * Create a stored payment method (tokenize a card).
     *
     * @param string $gatewayIdentifier UUID, public_id, or driver code
     * @param array  $data              Gateway-specific tokenization data
     *
     * @return GatewayResponse
     */
    public function createPaymentMethod(string $gatewayIdentifier, array $data): GatewayResponse
    {
        $gateway = $this->resolveGatewayModel($gatewayIdentifier);
        $driver  = $this->gatewayManager->driver($gateway->driver)
                       ->initialize($gateway->decryptedConfig(), $gateway->is_sandbox);

        if (!$driver->hasCapability('tokenization')) {
            return GatewayResponse::failure(
                eventType: GatewayResponse::EVENT_PAYMENT_FAILED,
                message:   "Gateway [{$gateway->driver}] does not support payment method tokenization.",
            );
        }

        $response = $driver->createPaymentMethod($data);

        $this->persistTransaction(
            gateway:  $gateway,
            response: $response,
            type:     'setup_intent',
        );

        return $response;
    }

    /**
     * Return the full driver manifest for the /drivers endpoint.
     * Used by the frontend to render the "Add Gateway" configuration form.
     *
     * @return array
     */
    public function getDriverManifest(): array
    {
        return $this->gatewayManager->getDriverManifest();
    }

    // -------------------------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the Gateway model from an identifier.
     *
     * @param string $identifier UUID, public_id, or driver code
     *
     * @return Gateway
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    private function resolveGatewayModel(string $identifier): Gateway
    {
        $companyUuid = session('company');

        return Gateway::query()
            ->when($companyUuid, fn ($q) => $q->where('company_uuid', $companyUuid))
            ->where(function ($q) use ($identifier) {
                $q->where('uuid', $identifier)
                  ->orWhere('public_id', $identifier)
                  ->orWhere('driver', $identifier);
            })
            ->where('status', 'active')
            ->firstOrFail();
    }

    /**
     * Persist a GatewayTransaction record for audit and idempotency.
     *
     * @param Gateway         $gateway
     * @param GatewayResponse $response
     * @param string          $type        Transaction type: 'purchase', 'refund', 'setup_intent'
     * @param string|null     $invoiceUuid Optional invoice UUID for linking
     *
     * @return GatewayTransaction
     */
    private function persistTransaction(
        Gateway         $gateway,
        GatewayResponse $response,
        string          $type,
        ?string         $invoiceUuid = null,
    ): GatewayTransaction {
        try {
            return GatewayTransaction::create([
                'company_uuid'         => $gateway->company_uuid,
                'gateway_uuid'         => $gateway->uuid,
                'gateway_reference_id' => $response->gatewayTransactionId,
                'type'                 => $type,
                'event_type'           => $response->eventType,
                'amount'               => $response->amount,
                'currency'             => $response->currency,
                'status'               => $response->status,
                'message'              => $response->message,
                'raw_response'         => array_merge(
                    $response->rawResponse,
                    array_filter(['invoice_uuid' => $invoiceUuid])
                ),
            ]);
        } catch (\Throwable $e) {
            // Log but don't fail the payment — the charge already went through
            Log::channel('ledger')->error('Failed to persist GatewayTransaction.', [
                'error'                  => $e->getMessage(),
                'gateway_reference_id'   => $response->gatewayTransactionId,
                'type'                   => $type,
            ]);

            // Return a minimal unsaved instance so callers don't need null checks
            return new GatewayTransaction([
                'gateway_uuid'         => $gateway->uuid,
                'gateway_reference_id' => $response->gatewayTransactionId,
                'type'                 => $type,
                'status'               => $response->status,
            ]);
        }
    }
}
