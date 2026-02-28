<?php

namespace Fleetbase\Ledger\Listeners;

use Fleetbase\Ledger\Events\PaymentFailed;
use Fleetbase\Ledger\Models\Invoice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * HandleFailedPayment Listener
 *
 * Queued listener that reacts to the PaymentFailed event.
 *
 * Responsibilities:
 *   1. Mark the related invoice as overdue or failed (if applicable)
 *   2. Mark the GatewayTransaction as processed (idempotency seal)
 *   3. Log the failure for operational visibility
 *
 * @package Fleetbase\Ledger\Listeners
 */
class HandleFailedPayment implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Handle the PaymentFailed event.
     *
     * @param PaymentFailed $event
     */
    public function handle(PaymentFailed $event): void
    {
        $response           = $event->response;
        $gatewayTransaction = $event->gatewayTransaction;
        $gateway            = $event->gateway;

        if ($gatewayTransaction->isProcessed()) {
            return;
        }

        try {
            // Update invoice status to 'overdue' if it was 'pending'
            $invoiceUuid = data_get($response->rawResponse, 'metadata.invoice_uuid');

            if ($invoiceUuid) {
                Invoice::where('uuid', $invoiceUuid)
                    ->orWhere('public_id', $invoiceUuid)
                    ->where('status', 'pending')
                    ->update(['status' => 'overdue']);
            }

            $gatewayTransaction->markAsProcessed();

            Log::channel('ledger')->warning('Payment failed processed.', [
                'gateway'                  => $gateway->driver,
                'gateway_transaction_uuid' => $gatewayTransaction->uuid,
                'error_code'               => $response->errorCode,
                'message'                  => $response->message,
            ]);
        } catch (\Throwable $e) {
            Log::channel('ledger')->error('HandleFailedPayment: failed.', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
