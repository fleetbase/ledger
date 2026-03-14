<?php

namespace Fleetbase\Ledger\Listeners;

use Fleetbase\Ledger\DTO\GatewayResponse;
use Fleetbase\Ledger\Events\PaymentSucceeded;
use Fleetbase\Ledger\Models\Account;
use Fleetbase\Ledger\Models\GatewayTransaction;
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
 *   1. If an invoice_uuid can be resolved from the gateway transaction,
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

        // Guard: do not process if already handled (idempotency).
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

            // Resolve the linked invoice (if any) using a robust multi-path helper.
            $invoiceUuid = $this->resolveInvoiceUuid($gatewayTransaction, $response);

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

            // Re-throw so the queue retries.
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the Ledger invoice UUID from a gateway transaction.
     *
     * Different gateway drivers and webhook paths store the invoice reference
     * in different locations. This method checks all known paths in order of
     * reliability and returns the first non-empty value found, or null.
     *
     * Known locations:
     *
     * 1. raw_response.invoice_uuid
     *    Set by PaymentService::persistTransaction() for direct API purchases
     *    (Stripe PaymentIntent, QPay invoice). This is the most reliable path
     *    because Ledger itself writes it.
     *
     * 2. raw_response.metadata.invoice_uuid
     *    Stripe stores the metadata object inside the PaymentIntent. When a
     *    Stripe webhook fires, the raw payload is stored as-is, so the
     *    metadata block is nested one level deeper.
     *
     * 3. raw_response.data.object.metadata.invoice_uuid
     *    Stripe webhook events wrap the PaymentIntent under data.object.
     *    Some webhook shapes nest it here instead of at the top level.
     *
     * 4. response->data['invoice_uuid']
     *    The GatewayResponse DTO's normalised data bag. Drivers may populate
     *    this for non-Stripe gateways that carry the reference in their own
     *    response body.
     *
     * @param GatewayTransaction $gatewayTransaction The persisted gateway transaction record
     * @param GatewayResponse    $response           The normalised gateway response DTO
     *
     * @return string|null The resolved invoice UUID/public_id, or null if not found
     */
    private function resolveInvoiceUuid(
        GatewayTransaction $gatewayTransaction,
        GatewayResponse $response,
    ): ?string {
        $raw = $gatewayTransaction->raw_response ?? [];

        return
            // 1. Top-level key written by PaymentService::persistTransaction()
            ($raw['invoice_uuid'] ?? null)
            // 2. Stripe PaymentIntent metadata (direct API purchase, stored flat)
            ?: ($raw['metadata']['invoice_uuid'] ?? null)
            // 3. Stripe webhook event: data.object.metadata.invoice_uuid
            ?: data_get($raw, 'data.object.metadata.invoice_uuid')
            // 4. Normalised GatewayResponse data bag (driver-specific)
            ?: ($response->data['invoice_uuid'] ?? null)
            ?: null;
    }
}
