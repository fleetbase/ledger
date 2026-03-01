<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Resources\v1\Transaction as TransactionResource;
use Fleetbase\Ledger\Models\Journal;
use Fleetbase\Models\Transaction;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'transaction';

    /**
     * The model to query.
     *
     * @var Transaction
     */
    public $model = Transaction::class;

    /**
     * Query for transactions system-wide.
     *
     * @return \Illuminate\Http\Response
     */
    public function query(Request $request)
    {
        $results = Transaction::where('company_uuid', session('company'))
            ->with(['customer', 'items'])
            ->when($request->filled('type'), function ($query) use ($request) {
                $query->where('type', $request->input('type'));
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->input('status'));
            })
            ->when($request->filled('gateway'), function ($query) use ($request) {
                $query->where('gateway', $request->input('gateway'));
            })
            ->when($request->filled('customer'), function ($query) use ($request) {
                $query->where('customer_uuid', $request->input('customer'));
            })
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('gateway_transaction_id', 'like', "%{$search}%")
                        ->orWhere('public_id', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('start_date'), function ($query) use ($request) {
                $query->where('created_at', '>=', $request->input('start_date'));
            })
            ->when($request->filled('end_date'), function ($query) use ($request) {
                $query->where('created_at', '<=', $request->input('end_date'));
            })
            ->orderBy($request->input('sort', 'created_at'), $request->input('order', 'desc'))
            ->paginate($request->input('limit', 15));

        return TransactionResource::collection($results);
    }

    /**
     * Find a single transaction.
     *
     * @return \Illuminate\Http\Response
     */
    public function find($id, Request $request)
    {
        $transaction = Transaction::where('company_uuid', session('company'))
            ->with(['customer', 'items'])
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)
                    ->orWhere('public_id', $id)
                    ->orWhere('gateway_transaction_id', $id);
            })
            ->firstOrFail();

        // Load the journal entry if it exists
        $journal = Journal::where('transaction_uuid', $transaction->uuid)->first();

        $response = new TransactionResource($transaction);
        $data     = $response->toArray(request());

        if ($journal) {
            $data['journal'] = [
                'uuid'                 => $journal->uuid,
                'debit_account_uuid'   => $journal->debit_account_uuid,
                'credit_account_uuid'  => $journal->credit_account_uuid,
                'amount'               => $journal->amount,
                'currency'             => $journal->currency,
                'description'          => $journal->description,
                'date'                 => $journal->date,
                'debit_account'        => $journal->debitAccount,
                'credit_account'       => $journal->creditAccount,
            ];
        }

        return response()->json($data);
    }
}
