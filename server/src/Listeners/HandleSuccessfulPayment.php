<?php

namespace Fleetbase\Ledger\Listeners;

use Fleetbase\Ledger\Events\PaymentSucceeded;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Services\LedgerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * HandleSuccessfulPayment Listener.
 *
 * Queued listener that reacts to the PaymentSucceeded event.
 *
 * Responsibilities:
 *   1. Mark the related invoice as paid (if an invoice_uuid is present in metadata)
 *   2. Create a revenue journal entry via LedgerService
 *   3. Mark the GatewayTransaction as processed (idempotency seal)
 *
 * This listener is queued to prevent blocking the webhook HTTP response.
 * If the job fails, it will be retried automatically by the queue worker.
 */
class HandleSuccessfulPayment implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 30;

    public function __construct(
        protected LedgerService $ledgerService,
    ) {
    }

    /**
     * Handle the PaymentSucceeded event.
     */
    public function handle(PaymentSucceeded $event): void
    {
        $response           = $event->response;
        $gatewayTransaction = $event->gatewayTransaction;
        $gateway            = $event->gateway;

        // Guard: do not process if already handled (idempotency)
        if ($gatewayTransaction->isProcessed()) {
            Log::channel('ledger')->info('HandleSuccessfulPayment: already processed, skipping.', [
                'gateway_transaction_uuid' => $gatewayTransaction->uuid,
            ]);

            return;
        }

        try {
            // 1. Mark the related invoice as paid if we have an invoice reference
            $invoiceUuid = $gatewayTransaction->raw_response['metadata']['invoice_uuid']
                ?? data_get($response->data, 'invoice_uuid')
                ?? null;

            if ($invoiceUuid) {
                $invoice = Invoice::where('uuid', $invoiceUuid)
                    ->orWhere('public_id', $invoiceUuid)
                    ->first();

                if ($invoice && $invoice->status !== 'paid') {
                    $invoice->update([
                        'status'    => 'paid',
                        'paid_at'   => now(),
                    ]);

                    Log::channel('ledger')->info('Invoice marked as paid.', [
                        'invoice_uuid' => $invoice->uuid,
                        'gateway'      => $gateway->driver,
                    ]);
                }
            }

            // 2. Create a revenue journal entry
            if ($response->amount && $response->currency) {
                $this->ledgerService->createJournalEntry(
                    type: 'revenue',
                    amount: $response->amount,
                    currency: $response->currency,
                    description: sprintf(
                        'Payment received via %s — Ref: %s',
                        $gateway->name,
                        $response->gatewayTransactionId
                    ),
                    metadata: [
                        'gateway_driver'           => $gateway->driver,
                        'gateway_transaction_id'   => $response->gatewayTransactionId,
                        'gateway_transaction_uuid' => $gatewayTransaction->uuid,
                        'invoice_uuid'             => $invoiceUuid,
                    ],
                );
            }

            // 3. Seal the gateway transaction as processed
            $gatewayTransaction->markAsProcessed();

            Log::channel('ledger')->info('HandleSuccessfulPayment: completed.', [
                'gateway_transaction_uuid' => $gatewayTransaction->uuid,
                'gateway_reference_id'     => $response->gatewayTransactionId,
            ]);
        } catch (\Throwable $e) {
            Log::channel('ledger')->error('HandleSuccessfulPayment: failed.', [
                'error'                    => $e->getMessage(),
                'gateway_transaction_uuid' => $gatewayTransaction->uuid,
            ]);

            // Re-throw so the queue retries
            throw $e;
        }
    }
}
