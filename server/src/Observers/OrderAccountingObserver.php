<?php

namespace Fleetbase\Ledger\Observers;

use Fleetbase\Ledger\Models\Account;
use Fleetbase\Ledger\Models\Journal;
use Fleetbase\Ledger\Services\LedgerService;
use Fleetbase\Ledger\Services\RevenueLifecycleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * OrderAccountingObserver (Ledger-owned).
 *
 * Observes the Fleet-Ops Order model for Ledger accounting integrations.
 * Revenue creation remains source-specific: Storefront orders are prepaid
 * point-of-sale revenue, while FleetOps operational orders are normally
 * recognized through purchase-rate/invoice flows.
 *
 * Order cancellation and reinstatement are handled generally for any order
 * with linked Ledger revenue artifacts.
 */
class OrderAccountingObserver
{
    private const CANCELED_STATUSES = ['canceled', 'cancelled', 'order_canceled'];

    public function __construct(
        protected LedgerService $ledgerService,
        protected RevenueLifecycleService $revenueLifecycleService,
    ) {
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
                    Log::channel('ledger')->info('[Ledger] OrderAccountingObserver: skipping Storefront order with zero total.', [
                        'order_uuid' => $order->uuid,
                    ]);

                    return;
                }

                $alreadyRecorded = Journal::where('type', 'storefront_sale')
                    ->where('meta->order_uuid', $order->uuid)
                    ->exists();

                if ($alreadyRecorded) {
                    return;
                }

                $cashAccount = Account::updateOrCreate(
                    ['company_uuid' => $companyUuid, 'code' => 'CASH-DEFAULT'],
                    [
                        'name'              => 'Cash',
                        'type'              => Account::TYPE_ASSET,
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

                $meta = [
                    'order_uuid'   => $order->uuid,
                    'order_id'     => $order->public_id,
                    'subject_uuid' => $order->uuid,
                    'subject_type' => get_class($order),
                ];

                foreach (['seed', 'seed_id'] as $seedMetaKey) {
                    if ($order->hasMeta($seedMetaKey)) {
                        $meta[$seedMetaKey] = $order->getMeta($seedMetaKey);
                    }
                }

                $this->ledgerService->createJournalEntry(
                    $cashAccount,
                    $revenueAccount,
                    $total,
                    "Storefront sale - Order {$order->public_id}",
                    [
                        'company_uuid' => $companyUuid,
                        'currency'     => $currency,
                        'journal_type' => 'storefront_sale',
                        'entry_date'   => now(),
                        'meta'         => $meta,
                    ]
                );

                Log::channel('ledger')->info('[Ledger] OrderAccountingObserver: Storefront sale journal entry created.', [
                    'order_uuid' => $order->uuid,
                    'total'      => $total,
                    'currency'   => $currency,
                ]);
            });
        } catch (\Throwable $e) {
            // Never let a Ledger failure abort the order creation flow.
            Log::channel('ledger')->error('[Ledger] OrderAccountingObserver: failed to create Storefront sale journal.', [
                'error'      => $e->getMessage(),
                'order_uuid' => $order->uuid ?? null,
            ]);
        }
    }

    /**
     * Handle the Order "updated" event.
     */
    public function updated($order): void
    {
        if (!$order->wasChanged('status')) {
            return;
        }

        $previousStatus = $this->normalizeStatus((string) $order->getOriginal('status'));
        $currentStatus  = $this->normalizeStatus((string) $order->status);

        if (!$this->isCanceledStatus($previousStatus) && $this->isCanceledStatus($currentStatus)) {
            $this->revenueLifecycleService->handleOrderCanceled($order, $previousStatus, $currentStatus);

            return;
        }

        if ($this->isCanceledStatus($previousStatus) && !$this->isCanceledStatus($currentStatus)) {
            $this->revenueLifecycleService->handleOrderRestored($order, $previousStatus, $currentStatus);
        }
    }

    public function deleted($order): void
    {
        $this->revenueLifecycleService->handleOrderDeleted($order);
    }

    public function restored($order): void
    {
        if ($this->isCanceledStatus($this->normalizeStatus((string) $order->status))) {
            return;
        }

        $this->revenueLifecycleService->handleOrderRestoredFromDelete($order);
    }

    private function normalizeStatus(string $status): string
    {
        return strtolower(trim($status));
    }

    private function isCanceledStatus(string $status): bool
    {
        return in_array($status, self::CANCELED_STATUSES, true);
    }
}
