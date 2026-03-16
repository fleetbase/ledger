<?php

namespace Fleetbase\Ledger\Services;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Ledger\Models\Account;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Models\InvoiceItem;
use Fleetbase\Ledger\Models\Transaction;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    /**
     * The ledger service instance.
     */
    protected LedgerService $ledgerService;

    /**
     * Create a new InvoiceService instance.
     */
    public function __construct(LedgerService $ledgerService)
    {
        $this->ledgerService = $ledgerService;
    }

    /**
     * Create an invoice from a FleetOps order.
     *
     * Generates a draft invoice with line items derived from the order's purchase
     * rate / service quote breakdown. The invoice is linked to the order via
     * `order_uuid` and to the customer via the order's customer polymorphic
     * relationship.
     *
     * @param Order       $order        the Fleet-Ops order to invoice
     * @param array       $options      Optional overrides (currency, due_date, notes, etc.).
     * @param object|null $purchaseRate The PurchaseRate model instance, if available.
     *                                  When supplied, line items are built from the
     *                                  service quote breakdown rather than order meta.
     */
    public function createFromOrder(Order $order, array $options = [], ?object $purchaseRate = null): Invoice
    {
        return DB::transaction(function () use ($order, $options, $purchaseRate) {
            // Resolve currency from options, order meta, or company default
            $currency = $options['currency']
                ?? $order->getMeta('currency')
                ?? 'USD';

            // Create the invoice header.
            // NOTE: do NOT pass 'number' here — the Invoice::boot() creating hook
            // reads the company's invoice_prefix from settings and calls
            // generateNumber($prefix) automatically. Passing a pre-generated number
            // would bypass the prefix setting entirely.
            $invoice = Invoice::create([
                'company_uuid'     => $order->company_uuid,
                'customer_uuid'    => $order->customer_uuid,
                'customer_type'    => $order->customer_type,
                'order_uuid'       => $order->uuid,
                // transaction_uuid links this invoice to the Fleet-Ops purchase
                // rate transaction so it appears in the invoice's Transactions tab.
                // PurchaseRateObserver passes this via $options after Fleet-Ops has
                // already created the Transaction record on the purchase rate.
                'transaction_uuid' => $options['transaction_uuid'] ?? null,
                // template_uuid: the boot hook will apply the company's default
                // template from Invoice Settings when this is left null.
                'template_uuid'    => $options['template_uuid'] ?? null,
                'number'           => $options['number'] ?? null,
                'date'             => $options['date'] ?? now(),
                'due_date'         => $options['due_date'] ?? null,
                'currency'         => $currency,
                'status'           => 'draft',
                'notes'            => $options['notes'] ?? null,
                'terms'            => $options['terms'] ?? null,
            ]);

            // Create line items — prefer purchase rate / service quote breakdown,
            // fall back to order payload entities and meta.
            if ($purchaseRate) {
                $this->createItemsFromPurchaseRate($invoice, $purchaseRate, $order);
            } else {
                $this->createItemsFromOrder($invoice, $order);
            }

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
     * Create invoice line items from a Fleet-Ops PurchaseRate / ServiceQuote.
     *
     * Resolution order:
     *   1. ServiceQuote items (base fee, distance fee, COD fee, etc.) — each
     *      ServiceQuoteItem becomes its own line item with the correct amount.
     *   2. Fallback — a single summary line item using serviceQuote->amount when
     *      no structured items are available.
     *
     * All amounts are already stored in the smallest currency unit (cents) by
     * Fleet-Ops, so no conversion is needed.
     */
    protected function createItemsFromPurchaseRate(Invoice $invoice, object $purchaseRate, Order $order): void
    {
        // Ensure the serviceQuote and its items are loaded.
        if (!$purchaseRate->relationLoaded('serviceQuote')) {
            $purchaseRate->load('serviceQuote.items');
        } elseif ($purchaseRate->serviceQuote && !$purchaseRate->serviceQuote->relationLoaded('items')) {
            $purchaseRate->serviceQuote->load('items');
        }

        $serviceQuote = $purchaseRate->serviceQuote;
        $itemsCreated = 0;

        // --- Strategy 1: ServiceQuote line items ---
        if ($serviceQuote && $serviceQuote->items && $serviceQuote->items->isNotEmpty()) {
            foreach ($serviceQuote->items as $item) {
                // ServiceQuoteItem stores amount in cents (smallest currency unit).
                $amount = (int) $item->amount;

                InvoiceItem::create([
                    'invoice_uuid' => $invoice->uuid,
                    'description'  => $item->details ?? $item->code ?? 'Service charge',
                    'quantity'     => 1,
                    'unit_price'   => $amount,
                    'amount'       => $amount,
                    'tax_rate'     => 0,
                    'tax_amount'   => 0,
                ]);

                $itemsCreated++;
            }
        }

        // --- Strategy 2: ServiceQuote total as a single summary line ---
        // Used when the quote has no individual items (e.g. flat-rate quotes).
        if ($itemsCreated === 0 && $serviceQuote) {
            // serviceQuote->amount is the virtual attribute that reads from
            // the serviceQuote's own `amount` column — already in cents.
            $total = (int) ($serviceQuote->amount ?? 0);

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

        // --- Strategy 3: Absolute fallback ---
        // If neither the quote nor its items could be resolved, fall back to the
        // generic order-based item creation so the invoice is never empty.
        if ($itemsCreated === 0) {
            $this->createItemsFromOrder($invoice, $order);
        }
    }

    /**
     * Record a payment against an invoice.
     *
     * Creates the double-entry journal entry (Debit Cash, Credit Accounts Receivable)
     * and updates the invoice's paid amount, balance, and status accordingly.
     *
     * @param int $amount Amount in smallest currency unit (e.g. cents).
     */
    public function recordPayment(Invoice $invoice, int $amount, array $options = []): Invoice
    {
        return DB::transaction(function () use ($invoice, $amount, $options) {
            $cashAccount = $this->getCashAccount($invoice->company_uuid);
            $arAccount   = $this->getAccountsReceivableAccount($invoice->company_uuid);

            // Create the authoritative Transaction record first so it appears in the Transactions list.
            // direction = 'credit' — money flows IN to the company (a receipt, not a disbursement).
            // payment_method and reference are forwarded from the controller options if provided.
            $paymentMethod = data_get($options, 'payment_method', 'manual');
            $reference     = data_get($options, 'reference');
            $transaction   = Transaction::create([
                'company_uuid'   => $invoice->company_uuid,
                'owner_uuid'     => $invoice->customer_uuid,
                'owner_type'     => $invoice->customer_type,
                'customer_uuid'  => $invoice->customer_uuid,
                'customer_type'  => $invoice->customer_type,
                // Payer = the customer settling the invoice
                'payer_uuid'     => $invoice->customer_uuid,
                'payer_type'     => $invoice->customer_type,
                // Payee = the company receiving the funds
                'payee_uuid'     => $invoice->company_uuid,
                'payee_type'     => \Fleetbase\Models\Company::class,
                'amount'         => $amount,
                'net_amount'     => $amount,
                'currency'       => $invoice->currency ?? 'USD',
                'description'    => "Payment for invoice {$invoice->number}",
                'type'           => 'invoice_payment',
                // credit = inbound money (company receives funds from the customer)
                'direction'      => 'credit',
                'status'         => 'completed',
                'payment_method' => $paymentMethod,
                'reference'      => $reference,
                'subject_uuid'   => $invoice->uuid,
                'subject_type'   => Invoice::class,
                'context_uuid'   => $invoice->uuid,
                'context_type'   => Invoice::class,
            ]);

            // DEBIT Cash (asset increases — money received), CREDIT Accounts Receivable (asset decreases — AR settled)
            $this->ledgerService->createJournalEntry(
                $cashAccount,
                $arAccount,
                $amount,
                "Payment for invoice {$invoice->number}",
                array_merge($options, [
                    'company_uuid'     => $invoice->company_uuid,
                    'currency'         => $invoice->currency,
                    'type'             => 'invoice_payment',
                    'transaction_uuid' => $transaction->uuid,
                    'subject_uuid'     => $invoice->uuid,
                    'subject_type'     => Invoice::class,
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

            // Link the transaction to the invoice on first payment
            if (!$invoice->transaction_uuid) {
                $invoice->transaction_uuid = $transaction->uuid;
            }

            $invoice->save();

            return $invoice;
        });
    }

    /**
     * Get or create the default cash account for a company.
     */
    protected function getCashAccount(string $companyUuid): Account
    {
        return Account::updateOrCreate(
            [
                'company_uuid' => $companyUuid,
                'code'         => 'CASH-DEFAULT',
            ],
            [
                'name'              => 'Cash',
                'type'              => 'asset',
                'description'       => 'Default cash account',
                'is_system_account' => true,
                'status'            => 'active',
            ]
        );
    }

    /**
     * Get or create the default accounts receivable account for a company.
     */
    protected function getAccountsReceivableAccount(string $companyUuid): Account
    {
        return Account::updateOrCreate(
            [
                'company_uuid' => $companyUuid,
                'code'         => 'AR-DEFAULT',
            ],
            [
                'name'              => 'Accounts Receivable',
                'type'              => 'asset',
                'description'       => 'Default accounts receivable account',
                'is_system_account' => true,
                'status'            => 'active',
            ]
        );
    }

    /**
     * Get or create the default revenue account for a company.
     */
    protected function getRevenueAccount(string $companyUuid): Account
    {
        return Account::updateOrCreate(
            [
                'company_uuid' => $companyUuid,
                'code'         => 'REV-DEFAULT',
            ],
            [
                'name'              => 'Sales Revenue',
                'type'              => Account::TYPE_REVENUE,
                'description'       => 'Default sales revenue account',
                'is_system_account' => true,
                'status'            => 'active',
            ]
        );
    }

    /**
     * Recognise revenue for an invoice.
     *
     * Creates a double-entry journal entry:
     *   DEBIT  Accounts Receivable  (asset increases — customer owes us)
     *   CREDIT Sales Revenue        (revenue increases — we earned it)
     *
     * This is the standard accrual-basis revenue recognition entry and is what
     * makes revenue appear in the Income Statement.
     */
    public function recogniseRevenue(Invoice $invoice): void
    {
        if ($invoice->total_amount <= 0) {
            return;
        }

        $arAccount      = $this->getAccountsReceivableAccount($invoice->company_uuid);
        $revenueAccount = $this->getRevenueAccount($invoice->company_uuid);

        $this->ledgerService->createJournalEntry(
            $arAccount,
            $revenueAccount,
            (int) $invoice->total_amount,
            "Revenue recognition for invoice {$invoice->number}",
            [
                'company_uuid' => $invoice->company_uuid,
                'currency'     => $invoice->currency,
                'type'         => 'revenue_recognition',
                'subject_uuid' => $invoice->uuid,
                'subject_type' => Invoice::class,
            ]
        );
    }
}
