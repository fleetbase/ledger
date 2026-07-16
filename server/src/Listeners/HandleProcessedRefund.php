<?php

namespace Fleetbase\Ledger\Listeners;

use Fleetbase\Ledger\Events\RefundProcessed;
use Fleetbase\Ledger\Models\Account;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Models\Transaction;
use Fleetbase\Ledger\Services\LedgerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * HandleProcessedRefund Listener.
 *
 * Queued listener that reacts to the RefundProcessed event.
 *
 * Responsibilities:
 *   1. Mark the related invoice as refunded
 *   2. Create a reversal journal entry
 *   3. Mark the GatewayTransaction as processed (idempotency seal)
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
            DB::transaction(function () use ($response, $gatewayTransaction, $gateway) {
                $invoiceUuid = $this->resolveInvoiceUuid($response, $gatewayTransaction);
                $invoice     = $invoiceUuid
                    ? Invoice::where('uuid', $invoiceUuid)->orWhere('public_id', $invoiceUuid)->first()
                    : null;

                $amount   = (int) $response->amount;
                $currency = $response->currency ?? $invoice?->currency ?? 'USD';

                if ($amount > 0) {
                    $transaction = Transaction::create([
                        'company_uuid'       => $gateway->company_uuid,
                        'owner_uuid'         => $invoice?->customer_uuid,
                        'owner_type'         => $invoice?->customer_type,
                        'customer_uuid'      => $invoice?->customer_uuid,
                        'customer_type'      => $invoice?->customer_type,
                        'payer_uuid'         => $gateway->company_uuid,
                        'payer_type'         => \Fleetbase\Models\Company::class,
                        'payee_uuid'         => $invoice?->customer_uuid,
                        'payee_type'         => $invoice?->customer_type,
                        'amount'             => $amount,
                        'net_amount'         => $amount,
                        'currency'           => $currency,
                        'description'        => "Refund for invoice " . ($invoice?->number ?? $response->gatewayTransactionId),
                        'type'               => 'gateway_refund',
                        'direction'          => 'debit',
                        'status'             => Transaction::STATUS_SUCCESS,
                        'settlement_status'  => data_get($response->data, 'refund_kind') === 'full' ? Transaction::SETTLEMENT_STATUS_REFUNDED : Transaction::SETTLEMENT_STATUS_PARTIALLY_REFUNDED,
                        'payment_method'     => $gateway->driver,
                        'reference'          => $response->gatewayTransactionId,
                        'settled_at'         => now(),
                        'settled_amount'     => $amount,
                        'settled_currency'   => $currency,
                        'subject_uuid'       => $invoice?->uuid,
                        'subject_type'       => $invoice ? Invoice::class : null,
                        'context_uuid'       => $invoice?->uuid,
                        'context_type'       => $invoice ? Invoice::class : null,
                    ]);

                    $refundExpense = $this->systemAccount($gateway->company_uuid, 'REFUNDS-DEFAULT', 'Refunds and Reversals', Account::TYPE_EXPENSE, 'Refunds issued through payment gateways.');
                    $cashAccount   = $this->systemAccount($gateway->company_uuid, 'CASH-DEFAULT', 'Cash', Account::TYPE_ASSET, 'Default cash account');

                    $this->ledgerService->createJournalEntry(
                        $refundExpense,
                        $cashAccount,
                        $amount,
                        sprintf('Refund issued via %s - Ref: %s', $gateway->name, $response->gatewayTransactionId),
                        [
                            'company_uuid'     => $gateway->company_uuid,
                            'currency'         => $currency,
                            'journal_type'     => 'gateway_refund',
                            'transaction_uuid' => $transaction->uuid,
                            'subject_uuid'     => $invoice?->uuid,
                            'subject_type'     => $invoice ? Invoice::class : null,
                            'meta'             => [
                                'gateway_driver'           => $gateway->driver,
                                'gateway_transaction_id'   => $response->gatewayTransactionId,
                                'gateway_transaction_uuid' => $gatewayTransaction->uuid,
                                'invoice_uuid'             => $invoice?->uuid,
                                'taler_refund_uri'         => data_get($response->data, 'taler_refund_uri'),
                            ],
                        ]
                    );

                    $gatewayTransaction->transaction_uuid = $transaction->uuid;
                }

                if ($invoice && $amount > 0) {
                    $previousRefunded = (int) data_get($invoice->meta, 'refunded_amount', 0);
                    $refundedAmount   = min((int) $invoice->total_amount, $previousRefunded + $amount);
                    $meta             = $invoice->meta ?? [];
                    data_set($meta, 'refunded_amount', $refundedAmount);
                    data_set($meta, 'last_refund_gateway_transaction_uuid', $gatewayTransaction->uuid);
                    data_set($meta, 'last_taler_refund_uri', data_get($response->data, 'taler_refund_uri'));
                    $invoice->meta   = $meta;
                    $invoice->status = $refundedAmount >= (int) $invoice->total_amount ? 'refunded' : 'partial';
                    $invoice->save();
                }

                $gatewayTransaction->refund_status       = data_get($response->data, 'refund_status', $response->status);
                $gatewayTransaction->refund_accepted_at  = data_get($response->data, 'wallet_status') === 'accepted' ? now() : null;
                $gatewayTransaction->raw_response        = array_merge($gatewayTransaction->raw_response ?? [], [
                    'data' => $response->data,
                ]);
                $gatewayTransaction->processed_at = now();
                $gatewayTransaction->save();
            });

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

    private function resolveInvoiceUuid($response, $gatewayTransaction): ?string
    {
        return data_get($response->data, 'invoice_uuid')
            ?: data_get($response->rawResponse, 'metadata.invoice_uuid')
            ?: data_get($response->rawResponse, 'invoice_uuid')
            ?: data_get($gatewayTransaction->raw_response, 'invoice_uuid')
            ?: data_get($gatewayTransaction->raw_response, 'data.invoice_uuid');
    }

    private function systemAccount(string $companyUuid, string $code, string $name, string $type, string $description): Account
    {
        return Account::updateOrCreate(
            ['company_uuid' => $companyUuid, 'code' => $code],
            [
                'name'              => $name,
                'type'              => $type,
                'description'       => $description,
                'is_system_account' => true,
                'status'            => 'active',
            ]
        );
    }
}
