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
     * Create an invoice from an order.
     *
     * @param Order $order
     * @param array $options
     *
     * @return Invoice
     */
    public function createFromOrder(Order $order, array $options = []): Invoice
    {
        return DB::transaction(function () use ($order, $options) {
            // Create the invoice
            $invoice = Invoice::create([
                'company_uuid'  => $order->company_uuid,
                'customer_uuid' => $order->customer_uuid,
                'customer_type' => $order->customer_type,
                'order_uuid'    => $order->uuid,
                'number'        => $options['number'] ?? Invoice::generateNumber(),
                'date'          => $options['date'] ?? now(),
                'due_date'      => $options['due_date'] ?? now()->addDays(30),
                'currency'      => $options['currency'] ?? 'USD',
                'status'        => 'draft',
                'notes'         => $options['notes'] ?? null,
                'terms'         => $options['terms'] ?? null,
            ]);

            // Create invoice items from order
            $this->createItemsFromOrder($invoice, $order);

            // Calculate totals
            $invoice->calculateTotals();
            $invoice->save();

            return $invoice;
        });
    }

    /**
     * Create invoice items from an order.
     *
     * @param Invoice $invoice
     * @param Order   $order
     *
     * @return void
     */
    protected function createItemsFromOrder(Invoice $invoice, Order $order): void
    {
        // Create a line item for the order
        InvoiceItem::create([
            'invoice_uuid' => $invoice->uuid,
            'description'  => "Order: {$order->public_id}",
            'quantity'     => 1,
            'unit_price'   => $order->getMeta('total', 0),
            'amount'       => $order->getMeta('total', 0),
            'tax_rate'     => 0,
            'tax_amount'   => 0,
        ]);
    }

    /**
     * Record a payment for an invoice.
     *
     * @param Invoice $invoice
     * @param int     $amount
     * @param array   $options
     *
     * @return Invoice
     */
    public function recordPayment(Invoice $invoice, int $amount, array $options = []): Invoice
    {
        return DB::transaction(function () use ($invoice, $amount, $options) {
            // Get accounts
            $cashAccount = $this->getCashAccount($invoice->company_uuid);
            $arAccount   = $this->getAccountsReceivableAccount($invoice->company_uuid);

            // Create journal entry: Debit Cash, Credit Accounts Receivable
            $journal = $this->ledgerService->createJournalEntry(
                $cashAccount,
                $arAccount,
                $amount,
                "Payment for invoice {$invoice->number}",
                array_merge($options, [
                    'company_uuid' => $invoice->company_uuid,
                    'type'         => 'invoice_payment',
                ])
            );

            // Update invoice
            $invoice->amount_paid += $amount;
            $invoice->balance = $invoice->total_amount - $invoice->amount_paid;

            if ($invoice->balance <= 0) {
                $invoice->markAsPaid();
            } elseif ($invoice->amount_paid > 0) {
                $invoice->status = 'partial';
            }

            // Link the transaction to the invoice
            if (!$invoice->transaction_uuid) {
                $invoice->transaction_uuid = $journal->transaction_uuid;
            }

            $invoice->save();

            return $invoice;
        });
    }

    /**
     * Get or create the cash account.
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
     * Get or create the accounts receivable account.
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
