<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Ledger\Http\Controllers\LedgerController;
use Fleetbase\Ledger\Http\Resources\v1\Transaction as TransactionResource;
use Fleetbase\Ledger\Models\Journal;
use Fleetbase\Models\Transaction;
use Illuminate\Http\Request;

/**
 * TransactionController.
 *
 * Read-only view of core-api Transaction records scoped to the authenticated
 * company. The findRecord() method is overridden to append the related journal
 * entry when present.
 */
class TransactionController extends LedgerController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'transaction';

    /**
     * Find a single transaction and append its journal entry if one exists.
     */
    public function findRecord(string $id, Request $request)
    {
        $transaction = Transaction::where('company_uuid', session('company'))
            ->with(['customer', 'items'])
            ->where(fn ($q) => $q->where('uuid', $id)
                ->orWhere('public_id', $id)
                ->orWhere('gateway_transaction_id', $id))
            ->firstOrFail();

        $journal = Journal::where('transaction_uuid', $transaction->uuid)
            ->with(['debitAccount', 'creditAccount'])
            ->first();

        $data = (new TransactionResource($transaction))->toArray($request);

        if ($journal) {
            $data['journal'] = [
                'uuid'                => $journal->uuid,
                'debit_account_uuid'  => $journal->debit_account_uuid,
                'credit_account_uuid' => $journal->credit_account_uuid,
                'amount'              => $journal->amount,
                'currency'            => $journal->currency,
                'description'         => $journal->description,
                'date'                => $journal->date,
                'debit_account'       => $journal->debitAccount,
                'credit_account'      => $journal->creditAccount,
            ];
        }

        return response()->json($data);
    }
}
