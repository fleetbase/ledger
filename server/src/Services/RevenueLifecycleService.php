<?php

namespace Fleetbase\Ledger\Services;

use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Models\Journal;
use Fleetbase\Ledger\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RevenueLifecycleService
{
    private const CANCELED_STATUSES = ['canceled', 'cancelled', 'order_canceled'];

    public function __construct(protected LedgerService $ledgerService)
    {
    }

    public function handleOrderCanceled($order, string $previousStatus, string $currentStatus, string $reason = 'order_canceled'): void
    {
        try {
            DB::transaction(function () use ($order, $previousStatus, $currentStatus, $reason) {
                $reversedStorefrontSales = $this->reverseStorefrontSaleJournals($order, $previousStatus, $currentStatus, $reason);
                $reversedInvoiceRevenue  = $this->reverseOrderInvoiceRevenue($order, $previousStatus, $currentStatus, $reason);
                $this->voidOrderTransactionIfUnpaid($order, $reason);

                if ($reversedStorefrontSales === 0 && $reversedInvoiceRevenue === 0) {
                    Log::channel('ledger')->info('[Ledger] RevenueLifecycleService: order lifecycle change had no linked Ledger revenue to reverse.', [
                        'order_uuid' => $order->uuid,
                        'reason'     => $reason,
                    ]);
                }
            });
        } catch (\Throwable $e) {
            Log::channel('ledger')->error('[Ledger] RevenueLifecycleService: failed to reverse order revenue.', [
                'error'      => $e->getMessage(),
                'order_uuid' => $order->uuid ?? null,
                'reason'     => $reason,
            ]);
        }
    }

    public function handleOrderRestored($order, string $previousStatus, string $currentStatus, string $reason = 'order_restored'): void
    {
        try {
            DB::transaction(function () use ($order, $previousStatus, $currentStatus, $reason) {
                $reinstatedStorefrontSales = $this->reinstateReversedJournals(
                    $order,
                    'storefront_sale_reversal',
                    'storefront_sale_reinstatement',
                    "Storefront sale reinstatement - Order {$order->public_id}",
                    $previousStatus,
                    $currentStatus,
                    $reason
                );

                $reinstatedInvoiceRevenue = $this->reinstateReversedJournals(
                    $order,
                    'revenue_recognition_reversal',
                    'revenue_recognition_reinstatement',
                    "Invoice revenue reinstatement - Order {$order->public_id}",
                    $previousStatus,
                    $currentStatus,
                    $reason
                );

                $this->restoreOrderInvoices($order, $reason);
                $this->restoreOrderTransactionIfLifecycleVoided($order, $reason);

                if ($reinstatedStorefrontSales === 0 && $reinstatedInvoiceRevenue === 0) {
                    Log::channel('ledger')->info('[Ledger] RevenueLifecycleService: order restore had no reversed Ledger revenue to reinstate.', [
                        'order_uuid' => $order->uuid,
                        'reason'     => $reason,
                    ]);
                }
            });
        } catch (\Throwable $e) {
            Log::channel('ledger')->error('[Ledger] RevenueLifecycleService: failed to reinstate order revenue.', [
                'error'      => $e->getMessage(),
                'order_uuid' => $order->uuid ?? null,
                'reason'     => $reason,
            ]);
        }
    }

    public function handleOrderDeleted($order): void
    {
        $this->handleOrderCanceled($order, (string) ($order->status ?? 'active'), 'deleted', 'order_deleted');
    }

    public function handleOrderRestoredFromDelete($order): void
    {
        $this->handleOrderRestored($order, 'deleted', (string) ($order->status ?? 'restored'), 'order_restored_from_delete');
    }

    public function handleInvoiceDeleting(Invoice $invoice): void
    {
        try {
            DB::transaction(function () use ($invoice) {
                if ($invoice->status === 'paid') {
                    $this->flagPaidInvoiceForReview($invoice, 'invoice_deleted');

                    return;
                }

                $this->cancelOpenInvoice($invoice, 'invoice_deleted');
                $this->reverseInvoiceRevenue($invoice, 'invoice_deleted');
                $this->voidInvoiceTransactionIfUnpaid($invoice, 'invoice_deleted');
            });
        } catch (\Throwable $e) {
            Log::channel('ledger')->error('[Ledger] RevenueLifecycleService: failed to process invoice deletion.', [
                'error'        => $e->getMessage(),
                'invoice_uuid' => $invoice->uuid ?? null,
            ]);
        }
    }

    public function handleInvoiceRestored(Invoice $invoice): void
    {
        try {
            DB::transaction(function () use ($invoice) {
                if ($invoice->getMeta('revenue_lifecycle_status_changed', false)) {
                    $previousInvoiceStatus = $invoice->getMeta('revenue_lifecycle_previous_status', 'draft');

                    $invoice->updateMeta([
                        'revenue_lifecycle_status_changed'   => false,
                        'revenue_lifecycle_restored_at'      => now()->toIso8601String(),
                        'revenue_lifecycle_restored_status'  => $previousInvoiceStatus,
                        'revenue_lifecycle_restore_reason'   => 'invoice_restored',
                    ]);

                    $invoice->updateQuietly([
                        'status' => $previousInvoiceStatus,
                    ]);
                }

                $this->restoreInvoiceTransactionIfLifecycleVoided($invoice, 'invoice_restored');
            });
        } catch (\Throwable $e) {
            Log::channel('ledger')->error('[Ledger] RevenueLifecycleService: failed to process invoice restoration.', [
                'error'        => $e->getMessage(),
                'invoice_uuid' => $invoice->uuid ?? null,
            ]);
        }
    }

    public function repairOrder($order, string $reason = 'repair'): void
    {
        $status = strtolower((string) ($order->status ?? ''));

        if ($order->deleted_at || in_array($status, self::CANCELED_STATUSES, true)) {
            $this->handleOrderCanceled($order, $status ?: 'unknown', $order->deleted_at ? 'deleted' : $status, $reason);
        }
    }

    public function repairInvoice(Invoice $invoice, string $reason = 'repair'): void
    {
        if ($invoice->deleted_at || in_array($invoice->status, ['void', 'voided', 'cancelled', 'canceled'], true)) {
            if ($invoice->status === 'paid') {
                $this->flagPaidInvoiceForReview($invoice, $reason);

                return;
            }

            $this->cancelOpenInvoice($invoice, $reason);
            $this->reverseInvoiceRevenue($invoice, $reason);
            $this->voidInvoiceTransactionIfUnpaid($invoice, $reason);
        }
    }

    private function reverseStorefrontSaleJournals($order, string $previousStatus, string $currentStatus, string $reason): int
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
            $currentStatus,
            $reason
        );
    }

    private function reverseOrderInvoiceRevenue($order, string $previousStatus, string $currentStatus, string $reason): int
    {
        $invoices = Invoice::withTrashed()->where('order_uuid', $order->uuid)->get();

        foreach ($invoices as $invoice) {
            if ($invoice->status === 'paid') {
                $this->flagPaidInvoiceForReview($invoice, $reason);
                continue;
            }

            $this->cancelOpenInvoice($invoice, $reason);
            $this->voidInvoiceTransactionIfUnpaid($invoice, $reason);
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
            $currentStatus,
            $reason
        );
    }

    private function reverseInvoiceRevenue(Invoice $invoice, string $reason): int
    {
        $journals = Journal::with(['debitAccount', 'creditAccount'])
            ->where('type', 'revenue_recognition')
            ->where('status', 'posted')
            ->where('meta->invoice_uuid', $invoice->uuid)
            ->get();

        return $this->reverseJournalsForInvoice($journals, $invoice, $reason);
    }

    private function reverseJournals($journals, $order, string $reversalType, string $description, string $previousStatus, string $currentStatus, string $reason): int
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
                'reverses_journal_uuid'    => $journal->uuid,
                'reverses_journal_id'      => $journal->public_id,
                'original_journal_type'    => $journal->type,
                'original_status'          => $previousStatus,
                'canceled_status'          => $currentStatus,
                'revenue_lifecycle_reason' => $reason,
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

    private function reverseJournalsForInvoice($journals, Invoice $invoice, string $reason): int
    {
        $created = 0;

        foreach ($journals as $journal) {
            if (!$journal->debitAccount || !$journal->creditAccount || $journal->amount <= 0) {
                continue;
            }

            if ($this->journalIsCurrentlyReversed($journal->uuid, 'revenue_recognition_reversal')) {
                continue;
            }

            $this->ledgerService->createJournalEntry(
                $journal->creditAccount,
                $journal->debitAccount,
                (int) $journal->amount,
                "Invoice revenue reversal - Invoice {$invoice->number}",
                [
                    'company_uuid' => $journal->company_uuid,
                    'currency'     => $journal->currency,
                    'journal_type' => 'revenue_recognition_reversal',
                    'entry_date'   => now(),
                    'meta'         => [
                        'invoice_uuid'              => $invoice->uuid,
                        'invoice_id'                => $invoice->public_id,
                        'reverses_journal_uuid'     => $journal->uuid,
                        'reverses_journal_id'       => $journal->public_id,
                        'original_journal_type'     => $journal->type,
                        'revenue_lifecycle_reason'  => $reason,
                    ],
                ]
            );

            $created++;
        }

        return $created;
    }

    private function reinstateReversedJournals($order, string $reversalType, string $reinstatementType, string $description, string $previousStatus, string $currentStatus, string $reason): int
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
                'reinstates_journal_uuid'  => $reversal->uuid,
                'reinstates_journal_id'    => $reversal->public_id,
                'reverses_journal_uuid'    => $reversal->getMeta('reverses_journal_uuid'),
                'reverses_journal_id'      => $reversal->getMeta('reverses_journal_id'),
                'reversal_journal_type'    => $reversal->type,
                'restored_from_status'     => $previousStatus,
                'restored_status'          => $currentStatus,
                'revenue_lifecycle_reason' => $reason,
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

    private function cancelOpenInvoice(Invoice $invoice, string $reason): void
    {
        if (in_array($invoice->status, ['paid', 'void', 'voided', 'cancelled', 'canceled'], true)) {
            return;
        }

        $previousStatus = $invoice->status;

        $invoice->updateMeta([
            'revenue_lifecycle_previous_status' => $previousStatus,
            'revenue_lifecycle_status_changed'  => true,
            'revenue_lifecycle_reason'          => $reason,
            'revenue_lifecycle_changed_at'      => now()->toIso8601String(),
        ]);

        $invoice->updateQuietly([
            'status' => $previousStatus === 'draft' ? 'void' : 'cancelled',
        ]);
    }

    private function restoreOrderInvoices($order, string $reason): void
    {
        Invoice::withTrashed()
            ->where('order_uuid', $order->uuid)
            ->whereIn('status', ['void', 'voided', 'cancelled', 'canceled'])
            ->get()
            ->each(function (Invoice $invoice) use ($reason) {
                if (!$invoice->getMeta('revenue_lifecycle_status_changed', false)) {
                    return;
                }

                $previousInvoiceStatus = $invoice->getMeta('revenue_lifecycle_previous_status', 'draft');

                $invoice->updateMeta([
                    'revenue_lifecycle_status_changed'   => false,
                    'revenue_lifecycle_restored_at'      => now()->toIso8601String(),
                    'revenue_lifecycle_restored_status'  => $previousInvoiceStatus,
                    'revenue_lifecycle_restore_reason'   => $reason,
                ]);

                $invoice->updateQuietly([
                    'status' => $previousInvoiceStatus,
                ]);
            });
    }

    private function flagPaidInvoiceForReview(Invoice $invoice, string $reason): void
    {
        $invoice->updateMeta([
            'revenue_lifecycle_requires_review' => true,
            'revenue_lifecycle_review_reason'   => $reason,
            'revenue_lifecycle_review_at'       => now()->toIso8601String(),
        ]);
    }

    private function voidInvoiceTransactionIfUnpaid(Invoice $invoice, string $reason): void
    {
        if ((int) $invoice->amount_paid > 0 || !$invoice->transaction_uuid) {
            return;
        }

        $this->voidTransaction($invoice->transaction_uuid, $reason, [
            'invoice_uuid' => $invoice->uuid,
            'invoice_id'   => $invoice->public_id,
        ]);
    }

    private function voidOrderTransactionIfUnpaid($order, string $reason): void
    {
        if (!$order->transaction_uuid) {
            return;
        }

        $hasPaidInvoice = Invoice::withTrashed()
            ->where('order_uuid', $order->uuid)
            ->where(function ($query) {
                $query->where('status', 'paid')->orWhere('amount_paid', '>', 0);
            })
            ->exists();

        if ($hasPaidInvoice) {
            return;
        }

        $this->voidTransaction($order->transaction_uuid, $reason, [
            'order_uuid' => $order->uuid,
            'order_id'   => $order->public_id,
        ]);
    }

    private function restoreOrderTransactionIfLifecycleVoided($order, string $reason): void
    {
        if (!$order->transaction_uuid) {
            return;
        }

        $transaction = Transaction::where('uuid', $order->transaction_uuid)->first();
        if (!$transaction || !$transaction->getMeta('revenue_lifecycle_voided', false)) {
            return;
        }

        $transaction->updateMeta([
            'revenue_lifecycle_voided'         => false,
            'revenue_lifecycle_restored_at'    => now()->toIso8601String(),
            'revenue_lifecycle_restore_reason' => $reason,
        ]);

        $transaction->updateQuietly([
            'status'    => $transaction->getMeta('revenue_lifecycle_previous_status', 'success'),
            'voided_at' => null,
        ]);
    }

    private function restoreInvoiceTransactionIfLifecycleVoided(Invoice $invoice, string $reason): void
    {
        if (!$invoice->transaction_uuid) {
            return;
        }

        $transaction = Transaction::where('uuid', $invoice->transaction_uuid)->first();
        if (!$transaction || !$transaction->getMeta('revenue_lifecycle_voided', false)) {
            return;
        }

        $transaction->updateMeta([
            'revenue_lifecycle_voided'         => false,
            'revenue_lifecycle_restored_at'    => now()->toIso8601String(),
            'revenue_lifecycle_restore_reason' => $reason,
        ]);

        $transaction->updateQuietly([
            'status'    => $transaction->getMeta('revenue_lifecycle_previous_status', 'success'),
            'voided_at' => null,
        ]);
    }

    private function voidTransaction(string $transactionUuid, string $reason, array $meta = []): void
    {
        $transaction = Transaction::where('uuid', $transactionUuid)->first();

        if (!$transaction || $transaction->voided_at || in_array($transaction->status, ['void', 'voided'], true)) {
            return;
        }

        $transaction->updateMeta(array_merge($meta, [
            'revenue_lifecycle_voided'          => true,
            'revenue_lifecycle_previous_status' => $transaction->status,
            'revenue_lifecycle_void_reason'     => $reason,
            'revenue_lifecycle_voided_at'       => now()->toIso8601String(),
        ]));

        $transaction->updateQuietly([
            'status'    => Transaction::STATUS_VOIDED,
            'voided_at' => now(),
        ]);
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
}
