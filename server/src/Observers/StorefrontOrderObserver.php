<?php

namespace Fleetbase\Ledger\Observers;

use Fleetbase\Ledger\Models\Account;
use Fleetbase\Ledger\Services\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * StorefrontOrderObserver (Ledger-owned).
 *
 * Observes the Fleet-Ops Order model and handles revenue recognition for
 * orders that originate from Storefront. Storefront orders are always
 * pre-paid — the customer pays before the order is created — so there is
 * no invoice-then-pay flow and no invoice is needed.
 *
 * Detection
 * ---------
 * A Storefront order is identified by `$order->type === 'storefront'`.
 * This is a first-class column on the orders table, always set by the
 * Storefront package, and is the authoritative signal.
 *
 * Accounting flow
 * ---------------
 *   DEBIT  CASH-DEFAULT  (asset ↑  — cash received from customer)
 *   CREDIT REV-DEFAULT   (revenue ↑ — earned at point of sale)
 *
 * No invoice is created. Storefront orders are point-of-sale transactions;
 * the order record itself is the receipt. Creating a Ledger invoice would
 * be confusing and redundant.
 *
 * This observer is registered in LedgerServiceProvider only when the
 * Fleet-Ops Order class is present. No hard dependency is introduced.
 */
class StorefrontOrderObserver
{
    public function __construct(protected LedgerService $ledgerService)
    {
    }

    /**
     * Handle the Order "created" event.
     */
    public function created($order): void
    {
        if ($order->type !== 'storefront') {
            return;
        }

        try {
            DB::transaction(function () use ($order) {
                $companyUuid = $order->company_uuid;
                $currency    = $order->getMeta('currency', 'USD');
                $total       = (int) $order->getMeta('total', 0);

                if ($total <= 0) {
                    Log::channel('ledger')->info('[Ledger] StorefrontOrderObserver: skipping order with zero total.', [
                        'order_uuid' => $order->uuid,
                    ]);

                    return;
                }

                // Idempotency: skip if a journal entry already exists for this order.
                $alreadyRecorded = \Fleetbase\Ledger\Models\Journal::where(
                    'meta->order_uuid', $order->uuid
                )->exists();

                if ($alreadyRecorded) {
                    return;
                }

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
                    $total,
                    "Storefront sale — Order {$order->public_id}",
                    [
                        'company_uuid' => $companyUuid,
                        'currency'     => $currency,
                        'type'         => 'storefront_sale',
                        'subject_uuid' => $order->uuid,
                        'subject_type' => get_class($order),
                        'entry_date'   => now(),
                        'meta'         => [
                            'order_uuid' => $order->uuid,
                            'order_id'   => $order->public_id,
                        ],
                    ]
                );

                Log::channel('ledger')->info('[Ledger] StorefrontOrderObserver: journal entry created.', [
                    'order_uuid' => $order->uuid,
                    'total'      => $total,
                    'currency'   => $currency,
                ]);
            });
        } catch (\Throwable $e) {
            // Never let a Ledger failure abort the Storefront order creation flow.
            Log::channel('ledger')->error('[Ledger] StorefrontOrderObserver: failed.', [
                'error'      => $e->getMessage(),
                'order_uuid' => $order->uuid ?? null,
            ]);
        }
    }
}
