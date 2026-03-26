<?php

namespace Fleetbase\Ledger\Observers;

use Fleetbase\Ledger\Services\InvoiceService;
use Illuminate\Support\Facades\Log;

/**
 * PurchaseRateObserver (Ledger-owned).
 *
 * Observes the Fleet-Ops PurchaseRate model and automatically generates a
 * Ledger invoice whenever a purchase rate is created. This keeps all
 * revenue-recognition logic inside the Ledger package — Fleet-Ops has no
 * knowledge of Ledger and no hard dependency is introduced in either direction.
 *
 * The observer is registered in LedgerServiceProvider only when the Fleet-Ops
 * PurchaseRate class is present (i.e. the fleet-ops package is installed).
 * If fleet-ops is not installed the observer is silently skipped.
 *
 * Accounting flow triggered by this observer:
 *   Invoice created (draft)
 *     → InvoiceObserver fires InvoiceCreated event
 *       → InvoiceController::onAfterCreate calls recogniseRevenue()
 *         → DEBIT AR-DEFAULT, CREDIT REV-DEFAULT  (accrual revenue recognition)
 *
 * The invoice is created in `draft` status. It transitions to `paid` when
 * the customer pays via the public invoice portal or when a gateway webhook
 * confirms payment.
 */
class PurchaseRateObserver
{
    public function __construct(protected InvoiceService $invoiceService)
    {
    }

    /**
     * Handle the PurchaseRate "created" event.
     *
     * Runs after the PurchaseRateObserver in Fleet-Ops has already created the
     * Transaction and TransactionItems, so the purchase rate's transaction_uuid
     * is already set and can be linked to the invoice.
     */
    public function created($purchaseRate): void
    {
        try {
            // Resolve the order that owns this purchase rate.
            // PurchaseRate belongs to an order via payload_uuid.
            $order = $this->resolveOrder($purchaseRate);

            if (!$order) {
                Log::channel('ledger')->warning('[Ledger] PurchaseRateObserver: could not resolve order for purchase rate.', [
                    'purchase_rate_uuid' => $purchaseRate->uuid,
                    'payload_uuid'       => $purchaseRate->payload_uuid,
                ]);

                return;
            }

            // Skip if an invoice already exists for this order (idempotency).
            if ($this->invoiceAlreadyExists($order->uuid)) {
                return;
            }

            // Resolve currency: prefer the service quote's currency, fall back to
            // the company's country-derived currency, then USD.
            $currency = data_get($purchaseRate, 'serviceQuote.currency')
                ?? data_get($order, 'meta.currency')
                ?? 'USD';

            // Due date: use the order's scheduled_at date if set, otherwise +30 days.
            $dueDate = $order->scheduled_at
                ? \Carbon\Carbon::parse($order->scheduled_at)
                : now()->addDays(30);

            $invoice = $this->invoiceService->createFromOrder($order, [
                'currency'         => $currency,
                'due_date'         => $dueDate,
                'transaction_uuid' => $purchaseRate->transaction_uuid,
                'notes'            => "Auto-generated from Fleet-Ops order {$order->public_id}",
            ], $purchaseRate);

            Log::channel('ledger')->info('[Ledger] PurchaseRateObserver: invoice created for order.', [
                'invoice_uuid'       => $invoice->uuid,
                'invoice_number'     => $invoice->number,
                'order_uuid'         => $order->uuid,
                'purchase_rate_uuid' => $purchaseRate->uuid,
            ]);
        } catch (\Throwable $e) {
            // Never let a Ledger failure abort the Fleet-Ops order creation flow.
            Log::channel('ledger')->error('[Ledger] PurchaseRateObserver: failed to create invoice.', [
                'error'              => $e->getMessage(),
                'purchase_rate_uuid' => $purchaseRate->uuid ?? null,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the Order that owns this PurchaseRate.
     *
     * PurchaseRate is linked to an Order via payload_uuid. We use a raw DB
     * query so that this observer works even if the Fleet-Ops Order model is
     * not directly imported (avoiding a hard class dependency).
     */
    private function resolveOrder($purchaseRate): ?object
    {
        // If the relation is already loaded (e.g. in tests), use it.
        if (isset($purchaseRate->order) && $purchaseRate->order) {
            return $purchaseRate->order;
        }

        // Resolve via the fully-qualified Fleet-Ops Order class if available.
        $orderClass = 'Fleetbase\\FleetOps\\Models\\Order';
        if (class_exists($orderClass)) {
            return $orderClass::where('payload_uuid', $purchaseRate->payload_uuid)
                ->orWhere('purchase_rate_uuid', $purchaseRate->uuid)
                ->first();
        }

        return null;
    }

    /**
     * Check whether a Ledger invoice already exists for the given order UUID.
     */
    private function invoiceAlreadyExists(string $orderUuid): bool
    {
        return \Fleetbase\Ledger\Models\Invoice::where('order_uuid', $orderUuid)->exists();
    }
}
