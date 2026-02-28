<?php

namespace Fleetbase\Ledger\Services;

use Fleetbase\Ledger\Models\Account;
use Fleetbase\Ledger\Models\Journal;
use Fleetbase\Models\Transaction;
use Illuminate\Support\Facades\DB;

class LedgerService
{
    /**
     * Create a double-entry journal entry and the corresponding core Transaction record.
     *
     * Every financial movement in Ledger is represented as a pair of records:
     *   1. A `Transaction` (core-api primitive) - the canonical, auditable money-movement record.
     *   2. A `Journal` (Ledger model) - the double-entry bookkeeping entry that links the
     *      debit and credit accounts to that transaction.
     *
     * All monetary amounts are stored in the smallest currency unit (e.g. cents for USD).
     *
     * Supported $options keys:
     *   - company_uuid    (string)  Company context; falls back to session('company').
     *   - currency        (string)  ISO 4217 currency code; defaults to account currency or 'USD'.
     *   - type            (string)  Transaction type label (e.g. 'wallet_deposit', 'invoice_payment').
     *   - status          (string)  Transaction status; defaults to 'completed'.
     *   - date            (mixed)   Journal entry date; defaults to now().
     *   - transaction_id  (string)  External/gateway transaction reference ID.
     *   - subject_uuid    (string)  UUID of the polymorphic subject (order, driver, customer, etc.).
     *   - subject_type    (string)  Fully-qualified class name of the subject.
     *   - gateway_uuid    (string)  UUID of the payment gateway used (if applicable).
     *   - meta            (array)   Arbitrary key-value metadata stored on both records.
     *   - notes           (string)  Human-readable notes attached to the transaction.
     *
     * @param Account $debitAccount   The account to debit.
     * @param Account $creditAccount  The account to credit.
     * @param int     $amount         Amount in smallest currency unit (e.g. cents).
     * @param string  $description    Human-readable description of the entry.
     * @param array   $options        Additional options (see above).
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
            $companyUuid = $options['company_uuid'] ?? session('company');
            $currency    = $options['currency'] ?? $debitAccount->currency ?? 'USD';
            $type        = $options['type'] ?? 'ledger';
            $status      = $options['status'] ?? 'completed';
            $meta        = $options['meta'] ?? [];

            // Build the Transaction payload, populating every relevant field from the
            // core-api Transaction model so the record is fully queryable from the
            // standard transactions API without needing Ledger-specific queries.
            $transactionPayload = [
                'company_uuid' => $companyUuid,
                'amount'       => $amount,
                'currency'     => $currency,
                'description'  => $description,
                'type'         => $type,
                'status'       => $status,
                'meta'         => $meta,
            ];

            // Attach optional contextual fields when provided
            if (!empty($options['transaction_id'])) {
                $transactionPayload['gateway_transaction_id'] = $options['transaction_id'];
            }

            if (!empty($options['subject_uuid'])) {
                $transactionPayload['subject_uuid'] = $options['subject_uuid'];
            }

            if (!empty($options['subject_type'])) {
                $transactionPayload['subject_type'] = $options['subject_type'];
            }

            if (!empty($options['gateway_uuid'])) {
                $transactionPayload['gateway_uuid'] = $options['gateway_uuid'];
            }

            if (!empty($options['notes'])) {
                $transactionPayload['notes'] = $options['notes'];
            }

            // Create the canonical Transaction record in core-api
            $transaction = Transaction::create($transactionPayload);

            // Create the double-entry Journal record linking debit and credit accounts
            $journal = Journal::create([
                'company_uuid'        => $companyUuid,
                'transaction_uuid'    => $transaction->uuid,
                'debit_account_uuid'  => $debitAccount->uuid,
                'credit_account_uuid' => $creditAccount->uuid,
                'amount'              => $amount,
                'currency'            => $currency,
                'description'         => $description,
                'date'                => $options['date'] ?? now(),
                'meta'                => $meta,
            ]);

            // Recalculate and persist the cached balance on both affected accounts
            $debitAccount->updateBalance();
            $creditAccount->updateBalance();

            return $journal;
        });
    }

    /**
     * Transfer funds between two accounts.
     *
     * @param Account $fromAccount  Account to debit (source).
     * @param Account $toAccount    Account to credit (destination).
     * @param int     $amount       Amount in smallest currency unit.
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
        return $this->createJournalEntry(
            $fromAccount,
            $toAccount,
            $amount,
            $description,
            array_merge(['type' => 'transfer'], $options)
        );
    }

    /**
     * Record revenue received.
     *
     * Correct treatment: DEBIT Asset (cash/AR increases), CREDIT Revenue (revenue increases).
     *
     * @param Account $assetAccount    The asset account receiving the funds (debit).
     * @param Account $revenueAccount  The revenue account being recognised (credit).
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
        return $this->createJournalEntry(
            $assetAccount,
            $revenueAccount,
            $amount,
            $description,
            array_merge(['type' => 'revenue'], $options)
        );
    }

    /**
     * Record an expense incurred.
     *
     * Correct treatment: DEBIT Expense (expense increases), CREDIT Asset (cash/AP decreases).
     *
     * @param Account $expenseAccount  The expense account being charged (debit).
     * @param Account $assetAccount    The asset account being reduced (credit).
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
        return $this->createJournalEntry(
            $expenseAccount,
            $assetAccount,
            $amount,
            $description,
            array_merge(['type' => 'expense'], $options)
        );
    }

    /**
     * Get all journal entries for a specific account (the general ledger view).
     *
     * @param Account     $account
     * @param string|null $startDate  ISO date string (inclusive lower bound).
     * @param string|null $endDate    ISO date string (inclusive upper bound).
     *
     * @return \Illuminate\Support\Collection
     */
    public function getGeneralLedger(Account $account, ?string $startDate = null, ?string $endDate = null)
    {
        $query = Journal::with(['transaction', 'debitAccount', 'creditAccount'])
            ->where(function ($q) use ($account) {
                $q->where('debit_account_uuid', $account->uuid)
                    ->orWhere('credit_account_uuid', $account->uuid);
            });

        if ($startDate) {
            $query->where('date', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('date', '<=', $endDate);
        }

        return $query->orderBy('date', 'asc')->orderBy('created_at', 'asc')->get();
    }

    /**
     * Calculate the balance for an account at (or up to) a specific date.
     *
     * Uses the standard accounting normal balance rules:
     *   - Asset & Expense accounts: balance = debits - credits  (debit-normal)
     *   - Liability, Equity & Revenue accounts: balance = credits - debits  (credit-normal)
     *
     * @param Account $account
     * @param string  $date  ISO date string (inclusive upper bound).
     *
     * @return int  Balance in smallest currency unit.
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
            return (int) ($debits - $credits);
        }

        return (int) ($credits - $debits);
    }

    /**
     * Get a trial balance snapshot for a company.
     *
     * @param string      $companyUuid
     * @param string|null $asOfDate  ISO date string; defaults to today.
     *
     * @return array
     */
    public function getTrialBalance(string $companyUuid, ?string $asOfDate = null): array
    {
        $asOfDate = $asOfDate ?? now()->toDateString();

        $accounts = Account::where('company_uuid', $companyUuid)
            ->where('is_active', true)
            ->get()
            ->map(function (Account $account) use ($asOfDate) {
                $balance = $this->getBalanceAtDate($account, $asOfDate);

                return [
                    'account'      => $account,
                    'balance'      => $balance,
                    'debit_total'  => in_array($account->type, ['asset', 'expense']) ? max(0, $balance) : 0,
                    'credit_total' => in_array($account->type, ['liability', 'equity', 'revenue']) ? max(0, $balance) : 0,
                ];
            });

        $debitTotal  = $accounts->sum('debit_total');
        $creditTotal = $accounts->sum('credit_total');

        return [
            'accounts'     => $accounts,
            'debit_total'  => $debitTotal,
            'credit_total' => $creditTotal,
            'balanced'     => $debitTotal === $creditTotal,
            'as_of_date'   => $asOfDate,
        ];
    }
}
