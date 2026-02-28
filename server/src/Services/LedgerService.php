<?php

namespace Fleetbase\Ledger\Services;

use Fleetbase\Ledger\Models\Account;
use Fleetbase\Ledger\Models\Journal;
use Fleetbase\Models\Transaction;
use Illuminate\Support\Facades\DB;

class LedgerService
{
    /**
     * Create a journal entry with double-entry bookkeeping.
     *
     * @param Account $debitAccount
     * @param Account $creditAccount
     * @param int     $amount
     * @param string  $description
     * @param array   $options
     *
     * @return Journal
     */
    public function createJournalEntry(
        Account $debitAccount,
        Account $creditAccount,
        int $amount,
        string $description = '',
        array $options = []
    ): Journal {
        return DB::transaction(function () use ($debitAccount, $creditAccount, $amount, $description, $options) {
            // Create the core transaction record
            $transaction = Transaction::create([
                'company_uuid'          => $options['company_uuid'] ?? session('company'),
                'gateway_transaction_id' => $options['transaction_id'] ?? Transaction::generateNumber(),
                'amount'                => $amount,
                'currency'              => $options['currency'] ?? 'USD',
                'description'           => $description,
                'type'                  => $options['type'] ?? 'ledger',
                'status'                => $options['status'] ?? 'completed',
                'meta'                  => $options['meta'] ?? [],
            ]);

            // Create the journal entry
            $journal = Journal::create([
                'company_uuid'        => $transaction->company_uuid,
                'transaction_uuid'    => $transaction->uuid,
                'debit_account_uuid'  => $debitAccount->uuid,
                'credit_account_uuid' => $creditAccount->uuid,
                'amount'              => $amount,
                'currency'            => $transaction->currency,
                'description'         => $description,
                'date'                => $options['date'] ?? now(),
                'meta'                => $options['meta'] ?? [],
            ]);

            // Update account balances
            $debitAccount->updateBalance();
            $creditAccount->updateBalance();

            return $journal;
        });
    }

    /**
     * Transfer funds between two accounts.
     *
     * @param Account $fromAccount
     * @param Account $toAccount
     * @param int     $amount
     * @param string  $description
     * @param array   $options
     *
     * @return Journal
     */
    public function transfer(
        Account $fromAccount,
        Account $toAccount,
        int $amount,
        string $description = '',
        array $options = []
    ): Journal {
        return $this->createJournalEntry($fromAccount, $toAccount, $amount, $description, $options);
    }

    /**
     * Record revenue.
     *
     * @param Account $assetAccount
     * @param Account $revenueAccount
     * @param int     $amount
     * @param string  $description
     * @param array   $options
     *
     * @return Journal
     */
    public function recordRevenue(
        Account $assetAccount,
        Account $revenueAccount,
        int $amount,
        string $description = '',
        array $options = []
    ): Journal {
        return $this->createJournalEntry($assetAccount, $revenueAccount, $amount, $description, $options);
    }

    /**
     * Record an expense.
     *
     * @param Account $expenseAccount
     * @param Account $assetAccount
     * @param int     $amount
     * @param string  $description
     * @param array   $options
     *
     * @return Journal
     */
    public function recordExpense(
        Account $expenseAccount,
        Account $assetAccount,
        int $amount,
        string $description = '',
        array $options = []
    ): Journal {
        return $this->createJournalEntry($expenseAccount, $assetAccount, $amount, $description, $options);
    }

    /**
     * Get the general ledger for a specific account.
     *
     * @param Account      $account
     * @param string|null  $startDate
     * @param string|null  $endDate
     *
     * @return \Illuminate\Support\Collection
     */
    public function getGeneralLedger(Account $account, ?string $startDate = null, ?string $endDate = null)
    {
        $query = Journal::where(function ($q) use ($account) {
            $q->where('debit_account_uuid', $account->uuid)
                ->orWhere('credit_account_uuid', $account->uuid);
        });

        if ($startDate) {
            $query->where('date', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('date', '<=', $endDate);
        }

        return $query->orderBy('date', 'asc')->get();
    }

    /**
     * Calculate the balance for an account at a specific date.
     *
     * @param Account $account
     * @param string  $date
     *
     * @return int
     */
    public function getBalanceAtDate(Account $account, string $date): int
    {
        $debits = Journal::where('debit_account_uuid', $account->uuid)
            ->where('date', '<=', $date)
            ->sum('amount');

        $credits = Journal::where('credit_account_uuid', $account->uuid)
            ->where('date', '<=', $date)
            ->sum('amount');

        if (in_array($account->type, ['asset', 'expense'])) {
            return $debits - $credits;
        }

        return $credits - $debits;
    }
}
