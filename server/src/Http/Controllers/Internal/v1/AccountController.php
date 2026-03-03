<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Ledger\Http\Controllers\LedgerResourceController;
use Fleetbase\Ledger\Http\Resources\v1\Account as AccountResource;
use Fleetbase\Ledger\Models\Account;
use Fleetbase\Ledger\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends LedgerResourceController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'account';

    /**
     * Recalculate and update the balance for an account.
     */
    public function recalculateBalance(string $id, Request $request): AccountResource
    {
        $account = Account::where('company_uuid', session('company'))
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))
            ->firstOrFail();

        $account->updateBalance();

        return new AccountResource($account);
    }

    /**
     * Return the general ledger for a specific account.
     *
     * Returns journal entries for the account with a computed running balance,
     * plus a summary of total debits, credits, and net balance.
     */
    public function generalLedger(string $id, Request $request): JsonResponse
    {
        $account = Account::where('company_uuid', session('company'))
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))
            ->firstOrFail();

        $journals = app(LedgerService::class)->getGeneralLedger(
            $account,
            $request->input('date_from'),
            $request->input('date_to')
        );

        $isDebitNormal  = in_array($account->type, ['asset', 'expense']);
        $runningBalance = 0;
        $totalDebits    = 0;
        $totalCredits   = 0;

        $entries = $journals->map(function ($journal) use ($account, $isDebitNormal, &$runningBalance, &$totalDebits, &$totalCredits) {
            $isDebit  = $journal->debit_account_uuid === $account->uuid;
            $isCredit = $journal->credit_account_uuid === $account->uuid;

            $debitAmount  = $isDebit ? (int) $journal->amount : 0;
            $creditAmount = $isCredit ? (int) $journal->amount : 0;

            $totalDebits += $debitAmount;
            $totalCredits += $creditAmount;

            // Running balance follows normal balance convention:
            //   Debit-normal (asset, expense):    balance increases on debit, decreases on credit
            //   Credit-normal (liability, equity, revenue): balance increases on credit, decreases on debit
            if ($isDebitNormal) {
                $runningBalance += $debitAmount - $creditAmount;
            } else {
                $runningBalance += $creditAmount - $debitAmount;
            }

            return [
                'id'              => $journal->public_id,
                'date'            => $journal->entry_date?->toDateString(),
                'number'          => $journal->number,
                'type'            => $journal->type,
                'description'     => $journal->description ?? $journal->memo,
                'reference'       => $journal->reference,
                'debit_amount'    => $debitAmount,
                'credit_amount'   => $creditAmount,
                'running_balance' => $runningBalance,
                'currency'        => $journal->currency ?? $account->currency,
                'is_system_entry' => (bool) $journal->is_system_entry,
            ];
        });

        return response()->json([
            'account' => new AccountResource($account),
            'entries' => $entries,
            'summary' => [
                'total_debits'  => $totalDebits,
                'total_credits' => $totalCredits,
                'net_balance'   => $isDebitNormal ? ($totalDebits - $totalCredits) : ($totalCredits - $totalDebits),
                'currency'      => $account->currency,
                'entry_count'   => $entries->count(),
            ],
        ]);
    }
}
