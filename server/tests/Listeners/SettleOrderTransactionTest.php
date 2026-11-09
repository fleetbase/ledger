<?php

use Fleetbase\Ledger\Events\InvoicePaid;
use Fleetbase\Ledger\Listeners\SettleOrderTransaction;
use Fleetbase\Ledger\Models\Invoice;

/** A transaction that records being saved, as core transactions do. */
class SettleOrderTransactionFake
{
    public int $saved = 0;

    public function __construct(public ?string $settlement_status = 'unpaid')
    {
    }

    public function save(): bool
    {
        return ++$this->saved > 0;
    }
}

class SettleOrderTransactionProbe extends SettleOrderTransaction
{
    public array $transactions      = [];
    public array $orderLookups      = [];
    public array $orderTransactions = [];

    protected function findTransaction(?string $uuid): ?object
    {
        return $uuid ? ($this->transactions[$uuid] ?? null) : null;
    }

    protected function orderTransactionUuid(?string $orderUuid): ?string
    {
        $this->orderLookups[] = $orderUuid;

        return $orderUuid ? ($this->orderTransactions[$orderUuid] ?? null) : null;
    }
}

function settleOrderTransactionEvent(?string $orderUuid, ?string $transactionUuid = null): InvoicePaid
{
    $invoice = new Invoice();
    $invoice->setRawAttributes(['uuid' => 'invoice-1', 'order_uuid' => $orderUuid, 'transaction_uuid' => $transactionUuid], true);

    return new InvoicePaid($invoice);
}

test('a paid invoice settles the transaction it was raised for, once', function () {
    $listener                          = new SettleOrderTransactionProbe();
    $listener->transactions['txn-1']   = $transaction = new SettleOrderTransactionFake();

    expect($listener->handle(settleOrderTransactionEvent('order-1', 'txn-1')))->toBeTrue()
        ->and($transaction->settlement_status)->toBe('paid')
        ->and($listener->handle(settleOrderTransactionEvent('order-1', 'txn-1')))->toBeFalse()
        ->and($transaction->saved)->toBe(1)
        ->and($listener->orderLookups)->toBe([]);
});

test('an invoice without a transaction settles its order\'s transaction', function () {
    $listener                               = new SettleOrderTransactionProbe();
    $listener->orderTransactions['order-1'] = 'txn-order';
    $listener->transactions['txn-order']    = $transaction = new SettleOrderTransactionFake();

    expect($listener->handle(settleOrderTransactionEvent('order-1')))->toBeTrue()
        ->and($transaction->settlement_status)->toBe('paid');
});

test('invoices without an order or transaction, and missing transactions, are left alone', function () {
    $listener = new SettleOrderTransactionProbe();

    expect($listener->handle(settleOrderTransactionEvent('gone')))->toBeFalse()
        ->and($listener->handle(settleOrderTransactionEvent(null)))->toBeFalse()
        ->and($listener->handle(settleOrderTransactionEvent(null, 'txn-missing')))->toBeFalse();
});
