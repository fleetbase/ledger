<?php

namespace Fleetbase\Ledger\Observers;

use Fleetbase\Ledger\Models\Account;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Models\InvoiceItem;
use Fleetbase\Ledger\Services\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * StorefrontOrderObserver (Ledger-owned).
 *
 * Observes the Fleet-Ops Order model and handles revenue recognition for
 * orders that originate from Storefront. Storefront orders are always
 * pre-paid — the customer pays before the order is created — so there is
 * no invoice-then-pay flow. Instead, Ledger creates a receipt invoice
 * (status = 'paid', balance = 0) and immediately records the double-entry
 * journal entry for the cash received.
 *
 * Detection strategy
 * ------------------
 * A Storefront order is identified by the presence of the meta key
 * `storefront_id` (set by the Storefront package when it creates the order
 * via the Fleet-Ops API). If that key is absent the observer is a no-op.
 *
 * This observer is registered in LedgerServiceProvider only when the
 * Fleet-Ops Order class is present. No hard dependency is introduced.
 *
 * Accounting flow
 * ---------------
 *   DEBIT  CASH-DEFAULT  (asset ↑  — cash received from customer)
 *   CREDIT REV-DEFAULT   (revenue ↑ — earned at point of sale)
 *
 * A receipt invoice is also created so there is a human-readable document
 * and the transaction appears in the AR aging and invoice list views.
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
        // Only handle Storefront orders.
        if (!$this->isStorefrontOrder($order)) {
            return;
        }

        try {
            DB::transaction(function () use ($order) {
                $companyUuid = $order->company_uuid;
                $currency    = $order->getMeta('currency', 'USD');
                $total       = (int) $order->getMeta('total', 0);

                if ($total <= 0) {
                    // Nothing to record — order has no monetary value.
                    Log::channel('ledger')->info('[Ledger] StorefrontOrderObserver: skipping order with zero total.', [
                        'order_uuid' => $order->uuid,
                    ]);
                    return;
                }

                // Skip if a receipt invoice already exists for this order (idempotency).
                if (Invoice::where('order_uuid', $order->uuid)->exists()) {
                    return;
                }

                // 1. Create a receipt invoice in 'paid' status.
                //    We build it manually rather than calling InvoiceService::createFromOrder
                //    because that method creates a 'draft' invoice and calls recogniseRevenue
                //    (DEBIT AR / CREDIT Revenue). For Storefront we want DEBIT Cash / CREDIT
                //    Revenue directly — no AR leg since the customer has already paid.
                $invoice = Invoice::create([
                    'company_uuid'  => $companyUuid,
                    'customer_uuid' => $order->customer_uuid,
                    'customer_type' => $order->customer_type,
                    'order_uuid'    => $order->uuid,
                    'number'        => Invoice::generateNumber(),
                    'date'          => now(),
                    'due_date'      => now(),
                    'currency'      => $currency,
                    'status'        => 'paid',
                    'amount_paid'   => $total,
                    'balance'       => 0,
                    'notes'         => "Storefront receipt — Order {$order->public_id}",
                ]);

                // 2. Create line items from order meta.
                $this->createLineItems($invoice, $order, $total, $currency);

                // 3. Calculate and persist totals.
                $invoice->calculateTotals();
                // Override balance/amount_paid since calculateTotals resets them.
                $invoice->amount_paid = $total;
                $invoice->balance     = 0;
                $invoice->save();

                // 4. Record the double-entry journal entry:
                //    DEBIT Cash (asset ↑), CREDIT Revenue (revenue ↑)
                $cashAccount    = $this->getCashAccount($companyUuid, $currency);
                $revenueAccount = $this->getRevenueAccount($companyUuid);

                $this->ledgerService->createJournalEntry(
                    $cashAccount,
                    $revenueAccount,
                    $total,
                    "Storefront sale — Order {$order->public_id}",
                    [
                        'company_uuid'  => $companyUuid,
                        'currency'      => $currency,
                        'type'          => 'storefront_sale',
                        'subject_uuid'  => $invoice->uuid,
                        'subject_type'  => Invoice::class,
                        'entry_date'    => now(),
                        'meta'          => [
                            'order_uuid'     => $order->uuid,
                            'order_id'       => $order->public_id,
                            'storefront_id'  => $order->getMeta('storefront_id'),
                        ],
                    ]
                );

                Log::channel('ledger')->info('[Ledger] StorefrontOrderObserver: receipt invoice and journal entry created.', [
                    'invoice_uuid' => $invoice->uuid,
                    'order_uuid'   => $order->uuid,
                    'total'        => $total,
                    'currency'     => $currency,
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

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Determine whether this order originated from Storefront.
     *
     * Storefront sets `storefront_id` in the order meta when it creates an
     * order via the Fleet-Ops API. This is the most reliable signal available
     * without introducing a hard dependency on the Storefront package.
     */
    private function isStorefrontOrder($order): bool
    {
        return !empty($order->getMeta('storefront_id'));
    }

    /**
     * Create invoice line items from the order meta `items` array.
     * Falls back to a single summary line item if no structured items exist.
     */
    private function createLineItems(Invoice $invoice, $order, int $total, string $currency): void
    {
        $metaItems   = $order->getMeta('items', []);
        $itemsCreated = 0;

        if (is_array($metaItems) && count($metaItems) > 0) {
            foreach ($metaItems as $item) {
                $unitPrice = (int) ($item['price'] ?? $item['unit_price'] ?? 0);
                $quantity  = (int) ($item['quantity'] ?? $item['qty'] ?? 1);
                $quantity  = max(1, $quantity);

                InvoiceItem::create([
                    'invoice_uuid' => $invoice->uuid,
                    'description'  => $item['name'] ?? $item['description'] ?? 'Order item',
                    'quantity'     => $quantity,
                    'unit_price'   => $unitPrice,
                    'amount'       => $unitPrice * $quantity,
                    'tax_rate'     => (int) ($item['tax_rate'] ?? 0),
                    'tax_amount'   => (int) ($item['tax_amount'] ?? 0),
                ]);
                $itemsCreated++;
            }
        }

        // Delivery fee as a separate line item.
        $deliveryFee = (int) $order->getMeta('delivery_fee', 0);
        if ($deliveryFee > 0) {
            InvoiceItem::create([
                'invoice_uuid' => $invoice->uuid,
                'description'  => 'Delivery fee',
                'quantity'     => 1,
                'unit_price'   => $deliveryFee,
                'amount'       => $deliveryFee,
                'tax_rate'     => 0,
                'tax_amount'   => 0,
            ]);
            $itemsCreated++;
        }

        // Fallback: single summary line item.
        if ($itemsCreated === 0) {
            InvoiceItem::create([
                'invoice_uuid' => $invoice->uuid,
                'description'  => "Storefront order {$order->public_id}",
                'quantity'     => 1,
                'unit_price'   => $total,
                'amount'       => $total,
                'tax_rate'     => 0,
                'tax_amount'   => 0,
            ]);
        }
    }

    /**
     * Get or create the default cash account for a company.
     */
    private function getCashAccount(string $companyUuid, string $currency): Account
    {
        return Account::updateOrCreate(
            ['company_uuid' => $companyUuid, 'code' => 'CASH-DEFAULT'],
            [
                'name'              => 'Cash',
                'type'              => 'asset',
                'description'       => 'Default cash account',
                'is_system_account' => true,
                'status'            => 'active',
                'currency'          => $currency,
            ]
        );
    }

    /**
     * Get or create the default revenue account for a company.
     */
    private function getRevenueAccount(string $companyUuid): Account
    {
        return Account::updateOrCreate(
            ['company_uuid' => $companyUuid, 'code' => 'REV-DEFAULT'],
            [
                'name'              => 'Sales Revenue',
                'type'              => Account::TYPE_REVENUE,
                'description'       => 'Default sales revenue account',
                'is_system_account' => true,
                'status'            => 'active',
            ]
        );
    }
}
