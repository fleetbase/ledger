<?php

namespace Fleetbase\Ledger\Services;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Ledger\Models\Account;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Models\InvoiceItem;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    /**
     * The ledger service instance.
     *
     * @var LedgerService
     */
    protected LedgerService $ledgerService;

    /**
     * Create a new InvoiceService instance.
     *
     * @param LedgerService $ledgerService
     */
    public function __construct(LedgerService $ledgerService)
    {
        $this->ledgerService = $ledgerService;
    }

    /**
     * Create an invoice from a FleetOps order.
     *
     * Generates a draft invoice with line items derived from the order's payload
     * (entities/items, delivery fee, service fees, etc.). The invoice is linked
     * to the order via `order_uuid` and to the customer via the order's customer
     * polymorphic relationship.
     *
     * @param Order $order
     * @param array $options
     *
     * @return Invoice
     */
    public function createFromOrder(Order $order, array $options = []): Invoice
    {
        return DB::transaction(function () use ($order, $options) {
            // Resolve currency from options, order meta, or company default
            $currency = $options['currency']
                ?? $order->getMeta('currency')
                ?? 'USD';

            // Create the invoice header
            $invoice = Invoice::create([
                'company_uuid'  => $order->company_uuid,
                'customer_uuid' => $order->customer_uuid,
                'customer_type' => $order->customer_type,
                'order_uuid'    => $order->uuid,
                'number'        => $options['number'] ?? Invoice::generateNumber(),
                'date'          => $options['date'] ?? now(),
                'due_date'      => $options['due_date'] ?? now()->addDays(30),
                'currency'      => $currency,
                'status'        => 'draft',
                'notes'         => $options['notes'] ?? null,
                'terms'         => $options['terms'] ?? null,
            ]);

            // Create line items from the order's payload
            $this->createItemsFromOrder($invoice, $order);

            // Calculate and persist subtotal, tax, and total
            $invoice->calculateTotals();
            $invoice->save();

            return $invoice;
        });
    }

    /**
     * Create invoice line items from a FleetOps order.
     *
     * Resolution order for line items:
     *   1. Order payload entities (goods being transported) — each entity becomes a line item.
     *   2. Order meta `items` array — storefront-style line items stored in meta.
     *   3. Fallback — a single summary line item using the order's total from meta.
     *
     * Delivery fee and service fees stored in order meta are added as separate line items
     * so the invoice accurately reflects the full charge breakdown.
     *
     * @param Invoice $invoice
     * @param Order   $order
     *
     * @return void
     */
    protected function createItemsFromOrder(Invoice $invoice, Order $order): void
    {
        $itemsCreated = 0;

        // --- Strategy 1: Order payload entities (FleetOps native) ---
        // Each entity in the order payload represents a physical item being transported.
        if ($order->relationLoaded('payload') || $order->payload) {
            $payload = $order->payload;
            if ($payload && $payload->entities && $payload->entities->isNotEmpty()) {
                foreach ($payload->entities as $entity) {
                    $unitPrice = (int) ($entity->price ?? $entity->getMeta('price', 0));
                    $quantity  = (int) ($entity->qty ?? $entity->getMeta('qty', 1));
                    $quantity  = max(1, $quantity);

                    InvoiceItem::create([
                        'invoice_uuid' => $invoice->uuid,
                        'description'  => $entity->name ?? $entity->description ?? "Item from order {$order->public_id}",
                        'quantity'     => $quantity,
                        'unit_price'   => $unitPrice,
                        'amount'       => $unitPrice * $quantity,
                        'tax_rate'     => 0,
                        'tax_amount'   => 0,
                    ]);

                    $itemsCreated++;
                }
            }
        }

        // --- Strategy 2: Order meta `items` array (storefront-style) ---
        // Storefront orders store line items as a JSON array in order meta.
        if ($itemsCreated === 0) {
            $metaItems = $order->getMeta('items', []);
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
        }

        // --- Strategy 3: Fallback — single summary line item ---
        // If no structured items could be resolved, create a single line item
        // representing the order total so the invoice is never empty.
        if ($itemsCreated === 0) {
            $total = (int) $order->getMeta('total', 0);

            InvoiceItem::create([
                'invoice_uuid' => $invoice->uuid,
                'description'  => "Delivery service — Order {$order->public_id}",
                'quantity'     => 1,
                'unit_price'   => $total,
                'amount'       => $total,
                'tax_rate'     => 0,
                'tax_amount'   => 0,
            ]);

            $itemsCreated++;
        }

        // --- Delivery fee line item ---
        // Add a separate line item for the delivery fee if present in order meta.
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
        }

        // --- Service fee line item ---
        $serviceFee = (int) $order->getMeta('service_fee', 0);
        if ($serviceFee > 0) {
            InvoiceItem::create([
                'invoice_uuid' => $invoice->uuid,
                'description'  => 'Service fee',
                'quantity'     => 1,
                'unit_price'   => $serviceFee,
                'amount'       => $serviceFee,
                'tax_rate'     => 0,
                'tax_amount'   => 0,
            ]);
        }
    }

    /**
     * Record a payment against an invoice.
     *
     * Creates the double-entry journal entry (Debit Cash, Credit Accounts Receivable)
     * and updates the invoice's paid amount, balance, and status accordingly.
     *
     * @param Invoice $invoice
     * @param int     $amount   Amount in smallest currency unit (e.g. cents).
     * @param array   $options
     *
     * @return Invoice
     */
    public function recordPayment(Invoice $invoice, int $amount, array $options = []): Invoice
    {
        return DB::transaction(function () use ($invoice, $amount, $options) {
            $cashAccount = $this->getCashAccount($invoice->company_uuid);
            $arAccount   = $this->getAccountsReceivableAccount($invoice->company_uuid);

            // DEBIT Cash (asset increases — money received), CREDIT Accounts Receivable (asset decreases — AR settled)
            $journal = $this->ledgerService->createJournalEntry(
                $cashAccount,
                $arAccount,
                $amount,
                "Payment for invoice {$invoice->number}",
                array_merge($options, [
                    'company_uuid' => $invoice->company_uuid,
                    'currency'     => $invoice->currency,
                    'type'         => 'invoice_payment',
                    'subject_uuid' => $invoice->uuid,
                    'subject_type' => Invoice::class,
                ])
            );

            // Update invoice payment tracking
            $invoice->amount_paid += $amount;
            $invoice->balance      = $invoice->total_amount - $invoice->amount_paid;

            if ($invoice->balance <= 0) {
                $invoice->markAsPaid();
            } elseif ($invoice->amount_paid > 0) {
                $invoice->status = 'partial';
            }

            // Link the core Transaction to the invoice on first payment
            if (!$invoice->transaction_uuid) {
                $invoice->transaction_uuid = $journal->transaction_uuid;
            }

            $invoice->save();

            return $invoice;
        });
    }

    /**
     * Get or create the default cash account for a company.
     *
     * @param string $companyUuid
     *
     * @return Account
     */
    protected function getCashAccount(string $companyUuid): Account
    {
        return Account::firstOrCreate(
            [
                'company_uuid' => $companyUuid,
                'code'         => 'CASH-DEFAULT',
            ],
            [
                'name'              => 'Cash',
                'type'              => 'asset',
                'description'       => 'Default cash account',
                'is_system_account' => true,
            ]
        );
    }

    /**
     * Get or create the default accounts receivable account for a company.
     *
     * @param string $companyUuid
     *
     * @return Account
     */
    protected function getAccountsReceivableAccount(string $companyUuid): Account
    {
        return Account::firstOrCreate(
            [
                'company_uuid' => $companyUuid,
                'code'         => 'AR-DEFAULT',
            ],
            [
                'name'              => 'Accounts Receivable',
                'type'              => 'asset',
                'description'       => 'Default accounts receivable account',
                'is_system_account' => true,
            ]
        );
    }
}
