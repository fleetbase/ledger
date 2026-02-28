<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Ledger\Models\Journal;
use Fleetbase\Ledger\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JournalController extends Controller
{
    /**
     * The ledger service instance.
     *
     * @var LedgerService
     */
    protected LedgerService $ledgerService;

    /**
     * Create a new JournalController instance.
     *
     * @param LedgerService $ledgerService
     */
    public function __construct(LedgerService $ledgerService)
    {
        $this->ledgerService = $ledgerService;
    }

    /**
     * Query journal entries for the authenticated company.
     *
     * Supports filtering by:
     *   - debit_account_uuid  — entries where this account was debited
     *   - credit_account_uuid — entries where this account was credited
     *   - account_uuid        — entries where this account appears on either side
     *   - date_from           — inclusive start date (ISO format)
     *   - date_to             — inclusive end date (ISO format)
     *   - search              — partial match on description
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function query(Request $request): JsonResponse
    {
        $query = Journal::with(['transaction', 'debitAccount', 'creditAccount'])
            ->where('company_uuid', session('company'));

        // Filter by a specific account on either side of the entry
        if ($request->filled('account_uuid')) {
            $accountUuid = $request->input('account_uuid');
            $query->where(function ($q) use ($accountUuid) {
                $q->where('debit_account_uuid', $accountUuid)
                    ->orWhere('credit_account_uuid', $accountUuid);
            });
        }

        if ($request->filled('debit_account_uuid')) {
            $query->where('debit_account_uuid', $request->input('debit_account_uuid'));
        }

        if ($request->filled('credit_account_uuid')) {
            $query->where('credit_account_uuid', $request->input('credit_account_uuid'));
        }

        if ($request->filled('date_from')) {
            $query->where('date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('date', '<=', $request->input('date_to'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('description', 'like', "%{$search}%");
        }

        $results = $query
            ->orderBy($request->input('sort', 'date'), $request->input('order', 'desc'))
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('limit', 15));

        return response()->json($results);
    }

    /**
     * Find a single journal entry by UUID or public_id.
     *
     * @param string  $id
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function find(string $id, Request $request): JsonResponse
    {
        $journal = Journal::with(['transaction', 'debitAccount', 'creditAccount'])
            ->where('company_uuid', session('company'))
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->firstOrFail();

        return response()->json($journal);
    }

    /**
     * Create a manual journal entry.
     *
     * Manual entries allow operators to record adjustments, corrections, or
     * opening balances that are not generated automatically by system events.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function create(Request $request): JsonResponse
    {
        $request->validate([
            'debit_account_uuid'  => 'required|uuid|exists:ledger_accounts,uuid',
            'credit_account_uuid' => 'required|uuid|exists:ledger_accounts,uuid|different:debit_account_uuid',
            'amount'              => 'required|integer|min:1',
            'currency'            => 'nullable|string|size:3',
            'description'         => 'required|string|max:500',
            'date'                => 'nullable|date',
        ]);

        $debitAccount  = \Fleetbase\Ledger\Models\Account::where('company_uuid', session('company'))
            ->where('uuid', $request->input('debit_account_uuid'))
            ->firstOrFail();

        $creditAccount = \Fleetbase\Ledger\Models\Account::where('company_uuid', session('company'))
            ->where('uuid', $request->input('credit_account_uuid'))
            ->firstOrFail();

        $journal = $this->ledgerService->createJournalEntry(
            $debitAccount,
            $creditAccount,
            (int) $request->input('amount'),
            $request->input('description'),
            [
                'company_uuid' => session('company'),
                'currency'     => $request->input('currency', 'USD'),
                'type'         => 'manual_entry',
                'date'         => $request->input('date', now()),
            ]
        );

        return response()->json($journal->load(['transaction', 'debitAccount', 'creditAccount']), 201);
    }

    /**
     * Delete a journal entry.
     *
     * Only non-system-generated entries (type = 'manual_entry') may be deleted.
     * Deleting a journal entry does not reverse the associated Transaction record
     * but does recalculate the affected account balances.
     *
     * @param string  $id
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function delete(string $id, Request $request): JsonResponse
    {
        $journal = Journal::where('company_uuid', session('company'))
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->firstOrFail();

        // Prevent deletion of system-generated entries
        if ($journal->transaction && $journal->transaction->type !== 'manual_entry') {
            return response()->json(
                ['error' => 'Only manual journal entries may be deleted. System-generated entries are immutable.'],
                422
            );
        }

        // Capture accounts before deletion so we can recalculate their balances
        $debitAccount  = $journal->debitAccount;
        $creditAccount = $journal->creditAccount;

        $journal->delete();

        // Recalculate balances on both affected accounts
        if ($debitAccount) {
            $debitAccount->updateBalance();
        }
        if ($creditAccount) {
            $creditAccount->updateBalance();
        }

        return response()->json(['status' => 'ok']);
    }
}
