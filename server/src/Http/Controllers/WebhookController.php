<?php

namespace Fleetbase\Ledger\Http\Controllers;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Ledger\DTO\GatewayResponse;
use Fleetbase\Ledger\Events\PaymentFailed;
use Fleetbase\Ledger\Events\PaymentSucceeded;
use Fleetbase\Ledger\Events\RefundProcessed;
use Fleetbase\Ledger\Exceptions\WebhookSignatureException;
use Fleetbase\Ledger\Models\Gateway;
use Fleetbase\Ledger\Models\GatewayTransaction;
use Fleetbase\Ledger\PaymentGatewayManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * WebhookController.
 *
 * Handles all inbound webhook requests from payment gateways.
 *
 * Route: POST /ledger/webhooks/{driver}
 *
 * The flow for every webhook:
 *   1. Identify the gateway by driver code + company context
 *   2. Delegate signature verification and parsing to the driver
 *   3. Persist the GatewayTransaction record (idempotent via firstOrCreate)
 *   4. If the record already existed and was processed, return 200 immediately
 *   5. Dispatch the appropriate normalized Laravel event
 *   6. Return 200 to the gateway (always, to prevent retries on our processing errors)
 *
 * IMPORTANT: This controller always returns HTTP 200 to the gateway, even on
 * internal processing errors. This prevents the gateway from retrying the webhook
 * indefinitely. Internal errors are logged and the job is retried via the queue.
 *
 * IDEMPOTENCY NOTE:
 *   Stripe fires multiple events that share the same gateway_reference_id (pi_xxx).
 *   For example, payment_intent.created and payment_intent.succeeded both reference
 *   the same PaymentIntent ID. The unique constraint is on
 *   (gateway_reference_id, type, event_type) so each distinct event type gets its
 *   own row. We use firstOrCreate to safely handle concurrent deliveries.
 */
class WebhookController extends Controller
{
    public function __construct(
        protected PaymentGatewayManager $gatewayManager,
    ) {
    }

    /**
     * Handle an inbound webhook from a payment gateway.
     *
     * @param Request $request The incoming HTTP request
     * @param string  $driver  The driver code from the URL (e.g., 'stripe', 'qpay')
     *
     * @return JsonResponse Always returns 200 to prevent gateway retries
     */
    public function handle(Request $request, string $driver): JsonResponse
    {
        $companyUuid = $request->input('company_uuid')
            ?? $request->header('X-Company-UUID')
            ?? null;

        // Find the active gateway for this driver
        $gateway = Gateway::query()
            ->when($companyUuid, fn ($q) => $q->where('company_uuid', $companyUuid))
            ->where('driver', $driver)
            ->where('status', 'active')
            ->first();

        if (!$gateway) {
            Log::channel('ledger')->warning("Webhook received for unknown/inactive driver: {$driver}", [
                'company_uuid' => $companyUuid,
                'ip'           => $request->ip(),
            ]);

            // Return 200 to prevent retries for unconfigured gateways
            return response()->json(['message' => 'Gateway not configured.'], 200);
        }

        try {
            // Resolve and initialize the driver
            $driverInstance = $this->gatewayManager->driver($driver)
                ->initialize($gateway->decryptedConfig(), $gateway->is_sandbox);

            // Delegate signature verification and event parsing to the driver
            $response = $driverInstance->handleWebhook($request);
        } catch (WebhookSignatureException $e) {
            Log::channel('ledger')->error("Webhook signature verification failed for [{$driver}].", [
                'error'      => $e->getMessage(),
                'gateway_id' => $gateway->uuid,
                'ip'         => $request->ip(),
            ]);

            // Return 400 for signature failures so the gateway knows something is wrong
            return response()->json(['message' => 'Signature verification failed.'], 400);
        } catch (\Throwable $e) {
            Log::channel('ledger')->error("Webhook driver exception for [{$driver}].", [
                'error'      => $e->getMessage(),
                'gateway_id' => $gateway->uuid,
            ]);

            return response()->json(['message' => 'Webhook processing error.'], 200);
        }

        $gatewayReferenceId = $response->gatewayTransactionId;
        $eventType          = $response->eventType;

        // Persist the gateway transaction record idempotently.
        //
        // The unique key is (gateway_reference_id, type, event_type) so that
        // multiple Stripe events sharing the same pi_xxx reference ID (e.g.
        // payment_intent.created and payment_intent.succeeded) each get their
        // own row without colliding.
        //
        // firstOrCreate() returns the single Eloquent model. Use the model's
        // $wasRecentlyCreated property to determine if it was just inserted.
        try {
            $gatewayTransaction = GatewayTransaction::firstOrCreate(
                // Unique match columns
                [
                    'gateway_reference_id' => $gatewayReferenceId,
                    'type'                 => 'webhook_event',
                    'event_type'           => $eventType,
                ],
                // Values to set only on creation
                [
                    'company_uuid'  => $gateway->company_uuid,
                    'gateway_uuid'  => $gateway->uuid,
                    'amount'        => $response->amount,
                    'currency'      => $response->currency,
                    'status'        => $response->status,
                    'message'       => $response->message,
                    'raw_response'  => $response->rawResponse,
                ]
            );
        } catch (UniqueConstraintViolationException $e) {
            // Extremely rare race condition: two concurrent requests both passed
            // the firstOrCreate check and one lost. Treat as already-processed.
            Log::channel('ledger')->info("Webhook duplicate race condition, skipping. [{$driver}]", [
                'gateway_reference_id' => $gatewayReferenceId,
                'event_type'           => $eventType,
            ]);

            return response()->json(['message' => 'Already processed.'], 200);
        }

        if (!$gatewayTransaction->wasRecentlyCreated) {
            // Record already existed — either a duplicate delivery or a retry.
            Log::channel('ledger')->info("Webhook already recorded, skipping dispatch. [{$driver}]", [
                'gateway_reference_id' => $gatewayReferenceId,
                'event_type'           => $eventType,
                'processed_at'         => $gatewayTransaction->processed_at,
            ]);

            return response()->json(['message' => 'Already processed.'], 200);
        }

        // Dispatch the appropriate normalized event
        $this->dispatchEvent($response, $gateway, $gatewayTransaction);

        Log::channel('ledger')->info("Webhook received and queued for [{$driver}].", [
            'gateway_reference_id'     => $gatewayReferenceId,
            'event_type'               => $eventType,
            'gateway_transaction_uuid' => $gatewayTransaction->uuid,
        ]);

        return response()->json(['message' => 'Webhook received.'], 200);
    }

    /**
     * Dispatch the appropriate Laravel event based on the normalized event type.
     */
    private function dispatchEvent(
        GatewayResponse $response,
        Gateway $gateway,
        GatewayTransaction $gatewayTransaction,
    ): void {
        match ($response->eventType) {
            GatewayResponse::EVENT_PAYMENT_SUCCEEDED => PaymentSucceeded::dispatch($response, $gateway, $gatewayTransaction),
            GatewayResponse::EVENT_PAYMENT_FAILED    => PaymentFailed::dispatch($response, $gateway, $gatewayTransaction),
            GatewayResponse::EVENT_REFUND_PROCESSED  => RefundProcessed::dispatch($response, $gateway, $gatewayTransaction),
            default                                  => Log::channel('ledger')->info("Webhook event [{$response->eventType}] has no registered handler."),
        };
    }
}
