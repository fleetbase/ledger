<?php

namespace Fleetbase\Ledger\Listeners;

use Fleetbase\Ledger\Events\RefundProcessed;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Services\LedgerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * HandleProcessedRefund Listener
 *
 * Queued listener that reacts to the RefundProcessed event.
 *
 * Responsibilities:
 *   1. Mark the related invoice as refunded
 *   2. Create a reversal journal entry
 *   3. Mark the GatewayTransaction as processed (idempotency seal)
 *
 * @package Fleetbase\Ledger\Listeners
 */
class HandleProcessedRefund implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public function __construct(
        protected LedgerService $ledgerService,
    ) {
    }

    /**
     * Handle the RefundProcessed event.
     *
     * @param RefundProcessed $event
     */
    public function handle(RefundProcessed $event): void
    {
        $response           = $event->response;
        $gatewayTransaction = $event->gatewayTransaction;
        $gateway            = $event->gateway;

        if ($gatewayTransaction->isProcessed()) {
            return;
        }

        try {
            // Mark invoice as refunded
            $invoiceUuid = data_get($response->rawResponse, 'metadata.invoice_uuid');

            if ($invoiceUuid) {
                Invoice::where('uuid', $invoiceUuid)
                    ->orWhere('public_id', $invoiceUuid)
                    ->update(['status' => 'refunded']);
            }

            // Create a reversal journal entry
            if ($response->amount && $response->currency) {
                $this->ledgerService->createJournalEntry(
                    type:        'refund',
                    amount:      $response->amount,
                    currency:    $response->currency,
                    description: sprintf(
                        'Refund issued via %s — Ref: %s',
                        $gateway->name,
                        $response->gatewayTransactionId
                    ),
                    metadata: [
                        'gateway_driver'          => $gateway->driver,
                        'gateway_transaction_id'  => $response->gatewayTransactionId,
                        'gateway_transaction_uuid' => $gatewayTransaction->uuid,
                        'invoice_uuid'            => $invoiceUuid,
                    ],
                );
            }

            $gatewayTransaction->markAsProcessed();

            Log::channel('ledger')->info('Refund processed.', [
                'gateway'                  => $gateway->driver,
                'gateway_transaction_uuid' => $gatewayTransaction->uuid,
            ]);
        } catch (\Throwable $e) {
            Log::channel('ledger')->error('HandleProcessedRefund: failed.', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
