<?php

namespace Fleetbase\Ledger\Observers;

use Fleetbase\Ledger\Models\Account;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Models\Journal;
use Fleetbase\Ledger\Services\LedgerService;
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
            $this->handleOrderCanceled($order, $previousStatus, $currentStatus);

            return;
        }

        if ($this->isCanceledStatus($previousStatus) && !$this->isCanceledStatus($currentStatus)) {
            $this->handleOrderRestored($order, $previousStatus, $currentStatus);
        }
    }

    private function handleOrderCanceled($order, string $previousStatus, string $currentStatus): void
    {
        try {
            DB::transaction(function () use ($order, $previousStatus, $currentStatus) {
                $reversedStorefrontSales = $this->reverseStorefrontSaleJournals($order, $previousStatus, $currentStatus);
                $reversedInvoiceRevenue  = $this->reverseOrderInvoiceRevenue($order, $previousStatus, $currentStatus);

                if ($reversedStorefrontSales === 0 && $reversedInvoiceRevenue === 0) {
                    Log::channel('ledger')->info('[Ledger] OrderAccountingObserver: order canceled with no linked Ledger revenue to reverse.', [
                        'order_uuid' => $order->uuid,
                    ]);
                }
            });
        } catch (\Throwable $e) {
            // Never let a Ledger failure abort the order cancellation flow.
            Log::channel('ledger')->error('[Ledger] OrderAccountingObserver: failed to reverse order revenue.', [
                'error'      => $e->getMessage(),
                'order_uuid' => $order->uuid ?? null,
            ]);
        }
    }

    private function handleOrderRestored($order, string $previousStatus, string $currentStatus): void
    {
        try {
            DB::transaction(function () use ($order, $previousStatus, $currentStatus) {
                $reinstatedStorefrontSales = $this->reinstateReversedJournals(
                    $order,
                    'storefront_sale_reversal',
                    'storefront_sale_reinstatement',
                    "Storefront sale reinstatement - Order {$order->public_id}",
                    $previousStatus,
                    $currentStatus
                );

                $reinstatedInvoiceRevenue = $this->reinstateReversedJournals(
                    $order,
                    'revenue_recognition_reversal',
                    'revenue_recognition_reinstatement',
                    "Invoice revenue reinstatement - Order {$order->public_id}",
                    $previousStatus,
                    $currentStatus
                );

                $this->restoreOrderInvoices($order);

                if ($reinstatedStorefrontSales === 0 && $reinstatedInvoiceRevenue === 0) {
                    Log::channel('ledger')->info('[Ledger] OrderAccountingObserver: order restored with no reversed Ledger revenue to reinstate.', [
                        'order_uuid' => $order->uuid,
                    ]);
                }
            });
        } catch (\Throwable $e) {
            // Never let a Ledger failure abort the order restoration flow.
            Log::channel('ledger')->error('[Ledger] OrderAccountingObserver: failed to reinstate order revenue.', [
                'error'      => $e->getMessage(),
                'order_uuid' => $order->uuid ?? null,
            ]);
        }
    }

    private function reverseStorefrontSaleJournals($order, string $previousStatus, string $currentStatus): int
    {
        $journals = Journal::with(['debitAccount', 'creditAccount'])
            ->where('type', 'storefront_sale')
            ->where('status', 'posted')
            ->where('meta->order_uuid', $order->uuid)
            ->get();

        return $this->reverseJournals(
            $journals,
            $order,
            'storefront_sale_reversal',
            "Storefront sale reversal - Order {$order->public_id} canceled",
            $previousStatus,
            $currentStatus
        );
    }

    private function reverseOrderInvoiceRevenue($order, string $previousStatus, string $currentStatus): int
    {
        $invoices = Invoice::where('order_uuid', $order->uuid)->get();

        foreach ($invoices as $invoice) {
            $this->cancelOpenInvoice($invoice);
        }

        if ($invoices->isEmpty()) {
            return 0;
        }

        $journals = Journal::with(['debitAccount', 'creditAccount'])
            ->where('type', 'revenue_recognition')
            ->where('status', 'posted')
            ->whereIn('meta->invoice_uuid', $invoices->pluck('uuid')->all())
            ->get();

        return $this->reverseJournals(
            $journals,
            $order,
            'revenue_recognition_reversal',
            "Invoice revenue reversal - Order {$order->public_id} canceled",
            $previousStatus,
            $currentStatus
        );
    }

    private function reverseJournals($journals, $order, string $reversalType, string $description, string $previousStatus, string $currentStatus): int
    {
        $created = 0;

        foreach ($journals as $journal) {
            if (!$journal->debitAccount || !$journal->creditAccount || $journal->amount <= 0) {
                continue;
            }

            if ($this->journalIsCurrentlyReversed($journal->uuid, $reversalType)) {
                continue;
            }

            $meta = $this->correctionMeta($order, $journal, [
                'reverses_journal_uuid' => $journal->uuid,
                'reverses_journal_id'   => $journal->public_id,
                'original_journal_type' => $journal->type,
                'original_status'       => $previousStatus,
                'canceled_status'       => $currentStatus,
            ]);

            $this->ledgerService->createJournalEntry(
                $journal->creditAccount,
                $journal->debitAccount,
                (int) $journal->amount,
                $description,
                [
                    'company_uuid' => $journal->company_uuid,
                    'currency'     => $journal->currency,
                    'journal_type' => $reversalType,
                    'entry_date'   => now(),
                    'meta'         => $meta,
                ]
            );

            $created++;
        }

        return $created;
    }

    private function reinstateReversedJournals($order, string $reversalType, string $reinstatementType, string $description, string $previousStatus, string $currentStatus): int
    {
        $reversals = Journal::with(['debitAccount', 'creditAccount'])
            ->where('type', $reversalType)
            ->where('status', 'posted')
            ->where('meta->order_uuid', $order->uuid)
            ->get();

        $created = 0;

        foreach ($reversals as $reversal) {
            if (!$reversal->debitAccount || !$reversal->creditAccount || $reversal->amount <= 0) {
                continue;
            }

            if ($this->journalExists($reinstatementType, 'reinstates_journal_uuid', $reversal->uuid)) {
                continue;
            }

            $meta = $this->correctionMeta($order, $reversal, [
                'reinstates_journal_uuid' => $reversal->uuid,
                'reinstates_journal_id'   => $reversal->public_id,
                'reverses_journal_uuid'   => $reversal->getMeta('reverses_journal_uuid'),
                'reverses_journal_id'     => $reversal->getMeta('reverses_journal_id'),
                'reversal_journal_type'   => $reversal->type,
                'restored_from_status'    => $previousStatus,
                'restored_status'         => $currentStatus,
            ]);

            $this->ledgerService->createJournalEntry(
                $reversal->creditAccount,
                $reversal->debitAccount,
                (int) $reversal->amount,
                $description,
                [
                    'company_uuid' => $reversal->company_uuid,
                    'currency'     => $reversal->currency,
                    'journal_type' => $reinstatementType,
                    'entry_date'   => now(),
                    'meta'         => $meta,
                ]
            );

            $created++;
        }

        return $created;
    }

    private function cancelOpenInvoice(Invoice $invoice): void
    {
        if (in_array($invoice->status, ['paid', 'void', 'cancelled'], true)) {
            return;
        }

        $invoice->updateMeta([
            'order_cancellation_previous_status' => $invoice->status,
            'order_cancellation_status_changed'  => true,
        ]);

        $invoice->updateQuietly([
            'status' => $invoice->status === 'draft' ? 'void' : 'cancelled',
        ]);
    }

    private function restoreOrderInvoices($order): void
    {
        Invoice::where('order_uuid', $order->uuid)
            ->whereIn('status', ['void', 'cancelled'])
            ->get()
            ->each(function (Invoice $invoice) {
                if (!$invoice->getMeta('order_cancellation_status_changed', false)) {
                    return;
                }

                $previousInvoiceStatus = $invoice->getMeta('order_cancellation_previous_status', 'draft');

                $invoice->updateMeta([
                    'order_cancellation_status_changed'   => false,
                    'order_cancellation_restored_at'      => now()->toIso8601String(),
                    'order_cancellation_restored_status'  => $previousInvoiceStatus,
                ]);

                $invoice->updateQuietly([
                    'status' => $previousInvoiceStatus,
                ]);
            });
    }

    private function correctionMeta($order, Journal $journal, array $meta): array
    {
        $journalMeta = $journal->getMeta();

        $baseMeta = [
            'order_uuid'   => $order->uuid,
            'order_id'     => $order->public_id,
            'subject_uuid' => $order->uuid,
            'subject_type' => get_class($order),
        ];

        foreach (['invoice_uuid', 'seed', 'seed_id'] as $metaKey) {
            if (isset($journalMeta[$metaKey])) {
                $baseMeta[$metaKey] = $journalMeta[$metaKey];
            } elseif ($order->hasMeta($metaKey)) {
                $baseMeta[$metaKey] = $order->getMeta($metaKey);
            }
        }

        return array_merge($baseMeta, $meta);
    }

    private function journalExists(string $journalType, string $metaKey, string $journalUuid): bool
    {
        return Journal::where('type', $journalType)
            ->where("meta->{$metaKey}", $journalUuid)
            ->exists();
    }

    private function journalIsCurrentlyReversed(string $journalUuid, string $reversalType): bool
    {
        $latestReversal = Journal::where('type', $reversalType)
            ->where('meta->reverses_journal_uuid', $journalUuid)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$latestReversal) {
            return false;
        }

        $reinstatementType = str_replace('_reversal', '_reinstatement', $reversalType);

        return !$this->journalExists($reinstatementType, 'reinstates_journal_uuid', $latestReversal->uuid);
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
