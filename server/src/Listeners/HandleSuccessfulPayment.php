<?php

namespace Fleetbase\Ledger\Listeners;

use Fleetbase\Ledger\Events\PaymentSucceeded;
use Fleetbase\Ledger\Models\Account;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Services\InvoiceService;
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
 *   1. If an invoice_uuid is present in the gateway transaction metadata,
 *      call InvoiceService::recordPayment() to mark the invoice paid and
 *      create the DEBIT Cash / CREDIT AR journal entry.
 *   2. If no invoice is linked (e.g. a standalone gateway charge), fall back
 *      to a direct DEBIT Cash / CREDIT Revenue entry so revenue is never lost.
 *   3. Mark the GatewayTransaction as processed (idempotency seal).
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
        protected InvoiceService $invoiceService,
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
            $companyUuid = $gateway->company_uuid;
            $amount      = (int) $response->amount;
            $currency    = $response->currency ?? 'USD';

            // Resolve the linked invoice (if any).
            $invoiceUuid = $gatewayTransaction->raw_response['metadata']['invoice_uuid']
                ?? data_get($response->rawResponse, 'metadata.invoice_uuid')
                ?? data_get($response->data, 'invoice_uuid')
                ?? null;

            $invoice = null;
            if ($invoiceUuid) {
                $invoice = Invoice::where('uuid', $invoiceUuid)
                    ->orWhere('public_id', $invoiceUuid)
                    ->first();
            }

            if ($invoice && $amount > 0) {
                // Path A: Invoice-linked payment.
                // recordPayment() handles DEBIT Cash / CREDIT AR, updates invoice status.
                if ($invoice->status !== 'paid') {
                    $this->invoiceService->recordPayment($invoice, $amount, [
                        'payment_method'           => 'gateway',
                        'reference'                => $response->gatewayTransactionId,
                        'gateway_transaction_uuid' => $gatewayTransaction->uuid,
                        'company_uuid'             => $companyUuid,
                        'currency'                 => $currency,
                    ]);

                    Log::channel('ledger')->info('HandleSuccessfulPayment: invoice payment recorded.', [
                        'invoice_uuid'             => $invoice->uuid,
                        'amount'                   => $amount,
                        'gateway_transaction_uuid' => $gatewayTransaction->uuid,
                    ]);
                }
            } elseif ($amount > 0) {
                // Path B: No invoice linked — direct DEBIT Cash / CREDIT Revenue.
                $cashAccount = Account::updateOrCreate(
                    ['company_uuid' => $companyUuid, 'code' => 'CASH-DEFAULT'],
                    [
                        'name'              => 'Cash',
                        'type'              => 'asset',
                        'description'       => 'Default cash account',
                        'is_system_account' => true,
                        'status'            => 'active',
                    ]
                );
                $revenueAccount = Account::updateOrCreate(
                    ['company_uuid' => $companyUuid, 'code' => 'REV-DEFAULT'],
                    [
                        'name'              => 'Sales Revenue',
                        'type'              => Account::TYPE_REVENUE,
                        'description'       => 'Default sales revenue account',
                        'is_system_account' => true,
                        'status'            => 'active',
                    ]
                );

                $this->ledgerService->createJournalEntry(
                    $cashAccount,
                    $revenueAccount,
                    $amount,
                    sprintf(
                        'Payment received via %s — Ref: %s',
                        $gateway->name,
                        $response->gatewayTransactionId
                    ),
                    [
                        'company_uuid'             => $companyUuid,
                        'currency'                 => $currency,
                        'type'                     => 'gateway_payment',
                        'gateway_transaction_uuid' => $gatewayTransaction->uuid,
                        'meta'                     => [
                            'gateway_driver'         => $gateway->driver,
                            'gateway_transaction_id' => $response->gatewayTransactionId,
                            'invoice_uuid'           => $invoiceUuid,
                        ],
                    ]
                );

                Log::channel('ledger')->info('HandleSuccessfulPayment: fallback journal entry created (no invoice).', [
                    'amount'                   => $amount,
                    'currency'                 => $currency,
                    'gateway_transaction_uuid' => $gatewayTransaction->uuid,
                ]);
            }

            // Seal the gateway transaction as processed (idempotency).
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
