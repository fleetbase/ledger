<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Ledger\Http\Controllers\LedgerResourceController;
use Fleetbase\Ledger\Http\Resources\v1\Journal as JournalResource;
use Fleetbase\Ledger\Models\Journal;
use Fleetbase\Ledger\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JournalController extends LedgerResourceController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'journal';

    /**
     * Create a manual journal entry.
     *
     * Manual entries allow operators to record adjustments, corrections, or
     * opening balances that are not generated automatically by system events.
     * This action supplements the standard createRecord() provided by the trait
     * because it requires custom validation and service-layer orchestration.
     */
    public function createManual(Request $request): JsonResponse
    {
        $request->validate([
            'debit_account_uuid'  => 'required|uuid|exists:ledger_accounts,uuid',
            'credit_account_uuid' => 'required|uuid|exists:ledger_accounts,uuid|different:debit_account_uuid',
            'amount'              => 'required|integer|min:1',
            'currency'            => 'nullable|string|size:3',
            'description'         => 'required|string|max:500',
            'entry_date'          => 'nullable|date',
        ]);

        $debitAccount = \Fleetbase\Ledger\Models\Account::where('company_uuid', session('company'))
            ->where('uuid', $request->input('debit_account_uuid'))
            ->firstOrFail();

        $creditAccount = \Fleetbase\Ledger\Models\Account::where('company_uuid', session('company'))
            ->where('uuid', $request->input('credit_account_uuid'))
            ->firstOrFail();

        $journal = app(LedgerService::class)->createJournalEntry(
            $debitAccount,
            $creditAccount,
            (int) $request->input('amount'),
            $request->input('description'),
            [
                'company_uuid' => session('company'),
                'currency'     => $request->input('currency', 'USD'),
                'type'         => 'manual_entry',
                'entry_date'   => $request->input('entry_date', now()),
            ]
        );

        return response()->json(
            new JournalResource($journal->load(['debitAccount', 'creditAccount'])),
            201
        );
    }
}
