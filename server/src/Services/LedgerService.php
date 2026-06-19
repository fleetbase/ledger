<?php

namespace Fleetbase\Ledger\Services;

use Fleetbase\Ledger\Models\Account;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Models\Journal;
use Fleetbase\Ledger\Models\Transaction;
use Fleetbase\Ledger\Models\Wallet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * LedgerService.
 *
 * Core double-entry bookkeeping engine for the Ledger extension.
 *
 * Responsibilities:
 *   - Creating and managing journal entries (double-entry bookkeeping)
 *   - Providing account balance calculations
 *   - Generating financial statements:
 *       - Trial Balance
 *       - Balance Sheet (Assets = Liabilities + Equity)
 *       - Income Statement (Revenue - Expenses = Net Income)
 *       - Cash Flow Summary
 *       - Accounts Receivable Aging
 *   - Providing dashboard metrics
 *
 * All monetary values are stored and returned in the smallest currency unit (cents).
 */
class LedgerService
{
    // =========================================================================
    // Journal Entry Creation
    // =========================================================================

    /**
     * Create a double-entry journal entry (bookkeeping record only).
     *
     * This method creates ONLY the Journal (double-entry) record. It does NOT
     * create a Transaction record. The caller (WalletService, InvoiceService, etc.)
     * is responsible for creating the authoritative Transaction record and passing
     * its UUID via $options['transaction_uuid'] so the journal can be linked to it.
     *
     * This separation prevents duplicate Transaction records when wallet operations
     * (deposit, withdraw, transfer) call this method after already creating their
     * own Transaction records.
     *
     * All monetary amounts are stored in the smallest currency unit (e.g. cents for USD).
     *
     * Supported $options keys:
     *   - company_uuid      (string)  Company context; falls back to session('company').
     *   - currency          (string)  ISO 4217 currency code; defaults to account currency or 'USD'.
     *   - transaction_uuid  (string)  UUID of the caller's Transaction record to link to this journal.
     *   - reference         (string)  Human-readable reference (invoice number, order ID, etc.).
     *   - memo              (string)  Additional memo text; defaults to description.
     *   - journal_type      (string)  Journal entry type; defaults to 'general'.
     *   - is_system_entry   (bool)    Whether this is an automated system entry; defaults to true.
     *   - entry_date        (mixed)   Journal entry date; defaults to now().
     *   - meta              (array)   Arbitrary key-value metadata.
     *
     * @param Account $debitAccount  the account to debit
     * @param Account $creditAccount the account to credit
     * @param int     $amount        Amount in smallest currency unit (e.g. cents).
     * @param string  $description   human-readable description of the entry
     * @param array   $options       additional options (see above)
     */
    public function createJournalEntry(
        Account $debitAccount,
        Account $creditAccount,
        int $amount,
        string $description = '',
        array $options = [],
    ): Journal {
        return DB::transaction(function () use ($debitAccount, $creditAccount, $amount, $description, $options) {
            $companyUuid = $options['company_uuid'] ?? session('company');
            $currency    = $options['currency'] ?? $debitAccount->currency ?? 'USD';
            $meta        = $options['meta'] ?? [];
            foreach (['subject_uuid', 'subject_type', 'gateway_transaction_uuid'] as $metaKey) {
                if (array_key_exists($metaKey, $options) && !array_key_exists($metaKey, $meta)) {
                    $meta[$metaKey] = $options[$metaKey];
                }
            }

            // Create the double-entry Journal record, linked to the caller's Transaction if provided
            $journal = Journal::create([
                'company_uuid'        => $companyUuid,
                'transaction_uuid'    => $options['transaction_uuid'] ?? null,
                'debit_account_uuid'  => $debitAccount->uuid,
                'credit_account_uuid' => $creditAccount->uuid,
                'amount'              => $amount,
                'currency'            => $currency,
                'description'         => $description,
                'type'                => $options['journal_type'] ?? $options['type'] ?? 'general',
                'status'              => 'posted',
                'reference'           => $options['reference'] ?? null,
                'memo'                => $options['memo'] ?? $description,
                'is_system_entry'     => $options['is_system_entry'] ?? true,
                'entry_date'          => $options['entry_date'] ?? now(),
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
     */
    public function transfer(
        Account $fromAccount,
        Account $toAccount,
        int $amount,
        string $description = '',
        array $options = [],
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
     * DEBIT  Asset (cash/AR increases)
     * CREDIT Revenue (revenue increases)
     */
    public function recordRevenue(
        Account $assetAccount,
        Account $revenueAccount,
        int $amount,
        string $description = '',
        array $options = [],
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
     * DEBIT  Expense (expense increases)
     * CREDIT Asset   (cash/AP decreases)
     */
    public function recordExpense(
        Account $expenseAccount,
        Account $assetAccount,
        int $amount,
        string $description = '',
        array $options = [],
    ): Journal {
        return $this->createJournalEntry(
            $expenseAccount,
            $assetAccount,
            $amount,
            $description,
            array_merge(['type' => 'expense'], $options)
        );
    }

    // =========================================================================
    // Account Balance
    // =========================================================================

    /**
     * Get all journal entries for a specific account (general ledger view).
     */
    public function getGeneralLedger(Account $account, ?string $startDate = null, ?string $endDate = null): Collection
    {
        $query = Journal::with(['transaction', 'debitAccount', 'creditAccount'])
            ->where(function ($q) use ($account) {
                $q->where('debit_account_uuid', $account->uuid)
                  ->orWhere('credit_account_uuid', $account->uuid);
            });

        if ($startDate) {
            $query->where('entry_date', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('entry_date', '<=', $endDate);
        }

        return $query->orderBy('entry_date', 'asc')->orderBy('created_at', 'asc')->get();
    }

    /**
     * Calculate the balance for an account at (or up to) a specific date.
     *
     * Normal balance rules:
     *   - Asset & Expense:               balance = debits - credits  (debit-normal)
     *   - Liability, Equity & Revenue:   balance = credits - debits  (credit-normal)
     */
    public function getBalanceAtDate(Account $account, string $date): int
    {
        $debits  = Journal::where('debit_account_uuid', $account->uuid)
            ->where('entry_date', '<=', $date)
            ->sum('amount');

        $credits = Journal::where('credit_account_uuid', $account->uuid)
            ->where('entry_date', '<=', $date)
            ->sum('amount');

        if (in_array($account->type, [Account::TYPE_ASSET, Account::TYPE_EXPENSE])) {
            return (int) ($debits - $credits);
        }

        return (int) ($credits - $debits);
    }

    // =========================================================================
    // Trial Balance
    // =========================================================================

    /**
     * Generate a trial balance for a company.
     *
     * Lists every active account with its debit/credit balance as of a given date.
     * The sum of all debit-normal balances must equal the sum of all credit-normal
     * balances for the books to be in balance.
     *
     * @param string|null $asOfDate ISO date string; defaults to today
     */
    public function getTrialBalance(string $companyUuid, ?string $asOfDate = null): array
    {
        $asOfDate = $asOfDate ?? now()->toDateString();

        $accounts = Account::where('company_uuid', $companyUuid)
            ->where('status', 'active')
            ->orderBy('code')
            ->get()
            ->map(function (Account $account) use ($asOfDate) {
                $balance       = $this->getBalanceAtDate($account, $asOfDate);
                $isDebitNormal = in_array($account->type, [Account::TYPE_ASSET, Account::TYPE_EXPENSE]);

                return [
                    'account'      => $account,
                    'balance'      => $balance,
                    'debit_total'  => $isDebitNormal ? max(0, $balance) : 0,
                    'credit_total' => !$isDebitNormal ? max(0, $balance) : 0,
                ];
            });

        $debitTotal  = $accounts->sum('debit_total');
        $creditTotal = $accounts->sum('credit_total');

        return [
            'accounts'     => $accounts,
            'debit_total'  => (int) $debitTotal,
            'credit_total' => (int) $creditTotal,
            'balanced'     => $debitTotal === $creditTotal,
            'as_of_date'   => $asOfDate,
        ];
    }

    // =========================================================================
    // Balance Sheet
    // =========================================================================

    /**
     * Generate a Balance Sheet (Statement of Financial Position).
     *
     * Presents the accounting equation:
     *   Assets = Liabilities + Equity
     *
     * Assets are listed first (current then non-current), followed by
     * liabilities and equity. The report verifies that the equation holds.
     *
     * @param string|null $asOfDate ISO date string; defaults to today
     */
    public function getBalanceSheet(string $companyUuid, ?string $asOfDate = null): array
    {
        $asOfDate = $asOfDate ?? now()->toDateString();

        $accounts = Account::where('company_uuid', $companyUuid)
            ->where('status', 'active')
            ->orderBy('code')
            ->get();

        $assets      = [];
        $liabilities = [];
        $equity      = [];

        foreach ($accounts as $account) {
            $balance = $this->getBalanceAtDate($account, $asOfDate);

            // Only include accounts with non-zero balances
            if ($balance === 0) {
                continue;
            }

            $row = [
                'uuid'    => $account->uuid,
                'code'    => $account->code,
                'name'    => $account->name,
                'balance' => $balance,
            ];

            switch ($account->type) {
                case Account::TYPE_ASSET:
                    $assets[] = $row;
                    break;
                case Account::TYPE_LIABILITY:
                    $liabilities[] = $row;
                    break;
                case Account::TYPE_EQUITY:
                    $equity[] = $row;
                    break;
            }
        }

        $totalAssets      = array_sum(array_column($assets, 'balance'));
        $totalLiabilities = array_sum(array_column($liabilities, 'balance'));
        $totalEquity      = array_sum(array_column($equity, 'balance'));

        return [
            'as_of_date'                   => $asOfDate,
            'assets'                       => $assets,
            'liabilities'                  => $liabilities,
            'equity'                       => $equity,
            'total_assets'                 => (int) $totalAssets,
            'total_liabilities'            => (int) $totalLiabilities,
            'total_equity'                 => (int) $totalEquity,
            'total_liabilities_and_equity' => (int) ($totalLiabilities + $totalEquity),
            'balanced'                     => $totalAssets === ($totalLiabilities + $totalEquity),
        ];
    }

    // =========================================================================
    // Income Statement (Profit & Loss)
    // =========================================================================

    /**
     * Generate an Income Statement (Profit & Loss Statement).
     *
     * Presents revenue and expenses over a period, resulting in net income (or loss).
     *
     *   Net Income = Total Revenue - Total Expenses
     *
     * @param string|null $startDate ISO date string; defaults to start of current month
     * @param string|null $endDate   ISO date string; defaults to today
     */
    public function getIncomeStatement(string $companyUuid, ?string $startDate = null, ?string $endDate = null): array
    {
        $startDate = $startDate ?? now()->startOfMonth()->toDateString();
        $endDate   = $endDate ?? now()->toDateString();

        $activity      = $this->getProfitAndLossActivity($companyUuid, $startDate, $endDate);
        $revenues      = $activity['revenues'];
        $expenses      = $activity['expenses'];
        $totalRevenue  = $activity['total_revenue'];
        $totalExpenses = $activity['total_expenses'];
        $netIncome     = $totalRevenue - $totalExpenses;

        return [
            'period' => [
                'from' => $startDate,
                'to'   => $endDate,
            ],
            'revenues'       => $revenues,
            'expenses'       => $expenses,
            'total_revenue'  => (int) $totalRevenue,
            'total_expenses' => (int) $totalExpenses,
            'net_income'     => (int) $netIncome,
            'profitable'     => $netIncome >= 0,
            'currency'       => $activity['currency'],
            'daily'          => $activity['daily'],
            'audit'          => $activity['audit'],
        ];
    }

    /**
     * Return normalized profit and loss journal activity for reports and dashboard widgets.
     */
    protected function getProfitAndLossActivity(string $companyUuid, string $startDate, string $endDate): array
    {
        $rows = Journal::where('company_uuid', $companyUuid)
            ->whereBetween('entry_date', [$startDate, $endDate])
            ->where(function ($query) {
                $query->whereHas('creditAccount', fn ($q) => $q->whereIn('type', [Account::TYPE_REVENUE, Account::TYPE_EXPENSE]))
                    ->orWhereHas('debitAccount', fn ($q) => $q->whereIn('type', [Account::TYPE_REVENUE, Account::TYPE_EXPENSE]));
            })
            ->with(['creditAccount', 'debitAccount'])
            ->orderBy('entry_date')
            ->orderBy('created_at')
            ->get();

        [$journals, $duplicates] = $this->deduplicateProfitAndLossJournals($rows);
        $revenues                = [];
        $expenses                = [];
        $daily                   = collect();
        $revenueByType           = [];
        $expenseByType           = [];

        $cursor = now()->parse($startDate);
        $end    = now()->parse($endDate);

        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $daily->put($key, ['date' => $key, 'revenue' => 0, 'expenses' => 0]);
            $cursor->addDay();
        }

        foreach ($journals as $journal) {
            $date = $journal->entry_date instanceof \DateTimeInterface ? $journal->entry_date->format('Y-m-d') : (string) $journal->entry_date;

            if (!$daily->has($date)) {
                $daily->put($date, ['date' => $date, 'revenue' => 0, 'expenses' => 0]);
            }

            $dailyEntry = $daily->get($date);

            if ($journal->creditAccount?->type === Account::TYPE_REVENUE) {
                $amount     = (int) $journal->amount;
                $accountKey = $journal->creditAccount->uuid;
                $typeKey    = $journal->type ?: 'general';

                $revenues[$accountKey] ??= $this->makeProfitAndLossAccountRow($journal->creditAccount);
                $revenues[$accountKey]['balance'] += $amount;
                $dailyEntry['revenue'] += $amount;
                $revenueByType[$typeKey] = ($revenueByType[$typeKey] ?? 0) + $amount;
            }

            if ($journal->debitAccount?->type === Account::TYPE_REVENUE) {
                $amount     = (int) $journal->amount;
                $accountKey = $journal->debitAccount->uuid;
                $typeKey    = $journal->type ?: 'general';

                $revenues[$accountKey] ??= $this->makeProfitAndLossAccountRow($journal->debitAccount);
                $revenues[$accountKey]['balance'] -= $amount;
                $dailyEntry['revenue'] -= $amount;
                $revenueByType[$typeKey] = ($revenueByType[$typeKey] ?? 0) - $amount;
            }

            if ($journal->debitAccount?->type === Account::TYPE_EXPENSE) {
                $amount     = (int) $journal->amount;
                $accountKey = $journal->debitAccount->uuid;
                $typeKey    = $journal->type ?: 'general';

                $expenses[$accountKey] ??= $this->makeProfitAndLossAccountRow($journal->debitAccount);
                $expenses[$accountKey]['balance'] += $amount;
                $dailyEntry['expenses'] += $amount;
                $expenseByType[$typeKey] = ($expenseByType[$typeKey] ?? 0) + $amount;
            }

            if ($journal->creditAccount?->type === Account::TYPE_EXPENSE) {
                $amount     = (int) $journal->amount;
                $accountKey = $journal->creditAccount->uuid;
                $typeKey    = $journal->type ?: 'general';

                $expenses[$accountKey] ??= $this->makeProfitAndLossAccountRow($journal->creditAccount);
                $expenses[$accountKey]['balance'] -= $amount;
                $dailyEntry['expenses'] -= $amount;
                $expenseByType[$typeKey] = ($expenseByType[$typeKey] ?? 0) - $amount;
            }

            $daily->put($date, $dailyEntry);
        }

        $revenues = collect($revenues)->filter(fn ($row) => $row['balance'] !== 0)->sortBy('code')->values();
        $expenses = collect($expenses)->filter(fn ($row) => $row['balance'] !== 0)->sortBy('code')->values();
        $currency = $this->resolveCurrencyFromJournals($journals) ?? $this->resolveDashboardCurrency();

        return [
            'revenues'       => $revenues->all(),
            'expenses'       => $expenses->all(),
            'total_revenue'  => (int) $revenues->sum('balance'),
            'total_expenses' => (int) $expenses->sum('balance'),
            'daily'          => $daily->sortKeys()->values(),
            'currency'       => $currency,
            'audit'          => [
                'source'             => 'ledger_journals',
                'amount_unit'        => 'minor',
                'journal_rows'       => $rows->count(),
                'counted_rows'       => $journals->count(),
                'deduplicated_rows'  => $duplicates,
                'revenue_by_type'    => $revenueByType,
                'expense_by_type'    => $expenseByType,
                'duplicate_strategy' => 'source_record_fingerprint',
            ],
        ];
    }

    /**
     * Keep one accounting journal per authoritative invoice/order/gateway source.
     */
    protected function deduplicateProfitAndLossJournals(Collection $rows): array
    {
        $seen       = [];
        $duplicates = 0;
        $journals   = collect();

        foreach ($rows as $journal) {
            $key = $this->profitAndLossJournalFingerprint($journal);

            if ($key && array_key_exists($key, $seen)) {
                $duplicates++;
                continue;
            }

            if ($key) {
                $seen[$key] = true;
            }

            $journals->push($journal);
        }

        return [$journals, $duplicates];
    }

    /**
     * Build a stable source fingerprint for revenue/expense rows that are backed by another record.
     */
    protected function profitAndLossJournalFingerprint(Journal $journal): ?string
    {
        $meta        = $journal->meta ?? [];
        $companyUuid = $journal->company_uuid;
        $description = (string) $journal->description;

        if (str_ends_with((string) $journal->type, '_reversal')) {
            $reversesJournalUuid = data_get($meta, 'reverses_journal_uuid');

            return implode('|', [
                'journal-reversal',
                $companyUuid,
                (string) $journal->type,
                $reversesJournalUuid ?: $journal->uuid,
            ]);
        }

        if (str_ends_with((string) $journal->type, '_reinstatement')) {
            $reinstatesJournalUuid = data_get($meta, 'reinstates_journal_uuid');

            return implode('|', [
                'journal-reinstatement',
                $companyUuid,
                (string) $journal->type,
                $reinstatesJournalUuid ?: $journal->uuid,
            ]);
        }

        if (preg_match('/Revenue recognition for invoice\s+([^\s\[]+)/', $description, $matches)) {
            return implode('|', [
                'invoice-number',
                $companyUuid,
                $matches[1],
                (string) $journal->amount,
                (string) $journal->currency,
            ]);
        }

        $invoiceUuid = data_get($meta, 'invoice_uuid');
        if ($invoiceUuid) {
            return implode('|', ['invoice', $companyUuid, $invoiceUuid]);
        }

        $orderUuid = data_get($meta, 'order_uuid');
        if ($orderUuid) {
            return implode('|', ['order', $companyUuid, $orderUuid]);
        }

        $gatewayTransactionUuid = data_get($meta, 'gateway_transaction_uuid');
        if ($gatewayTransactionUuid) {
            return implode('|', ['gateway-transaction', $companyUuid, $gatewayTransactionUuid]);
        }

        $transactionUuid = $journal->transaction_uuid;
        if ($transactionUuid && in_array($journal->type, ['revenue', 'expense', 'gateway_payment', 'wallet_fee', 'refund'], true)) {
            return implode('|', ['transaction', $companyUuid, $transactionUuid, (string) $journal->type]);
        }

        return null;
    }

    /**
     * Build a report row from an account model.
     */
    protected function makeProfitAndLossAccountRow(Account $account): array
    {
        return [
            'uuid'    => $account->uuid,
            'code'    => $account->code,
            'name'    => $account->name,
            'balance' => 0,
        ];
    }

    /**
     * Resolve the currency used by counted journal rows when all rows agree.
     */
    protected function resolveCurrencyFromJournals(Collection $journals): ?string
    {
        $currencies = $journals->pluck('currency')->filter()->unique()->values();

        return $currencies->count() === 1 ? $currencies->first() : null;
    }

    // =========================================================================
    // Cash Flow Summary
    // =========================================================================

    /**
     * Generate a Cash Flow Summary.
     *
     * A simplified cash flow statement derived from wallet transactions.
     * Groups cash movements into three standard categories:
     *   - Operating Activities  (earnings, fees, refunds, adjustments)
     *   - Financing Activities  (deposits, withdrawals, payouts, transfers)
     *   - Investing Activities  (placeholder for future asset purchases)
     *
     * Also reports the opening and closing balance of the Cash account (code 1000)
     * from the journal ledger for cross-validation.
     *
     * @param string|null $startDate ISO date string; defaults to start of current month
     * @param string|null $endDate   ISO date string; defaults to today
     */
    public function getCashFlowSummary(string $companyUuid, ?string $startDate = null, ?string $endDate = null): array
    {
        $startDate = $startDate ?? now()->startOfMonth()->toDateString();
        $endDate   = $endDate ?? now()->toDateString();

        // Derive cash flows from wallet transactions (most reliable cash proxy)
        $walletStats = Transaction::where('company_uuid', $companyUuid)
            ->where('status', 'completed')
            ->whereBetween(DB::raw('DATE(created_at)'), [$startDate, $endDate])
            ->select(
                'type',
                'direction',
                'currency',
                DB::raw('sum(amount) as total'),
                DB::raw('count(*) as count')
            )
            ->groupBy('type', 'direction', 'currency')
            ->get();

        $operating = [];
        $financing = [];
        $investing = [];

        $operatingTypes = ['earning', 'fee', 'adjustment', 'refund'];
        $financingTypes = ['deposit', 'withdrawal', 'payout', 'transfer_in', 'transfer_out'];

        foreach ($walletStats as $row) {
            $entry = [
                'type'      => $row->type,
                'direction' => $row->direction,
                'currency'  => $row->currency,
                'total'     => (int) $row->total,
                'count'     => (int) $row->count,
            ];

            if (in_array($row->type, $operatingTypes)) {
                $operating[] = $entry;
            } elseif (in_array($row->type, $financingTypes)) {
                $financing[] = $entry;
            } else {
                $investing[] = $entry;
            }
        }

        $netOperating = $this->computeNetFlow($operating);
        $netFinancing = $this->computeNetFlow($financing);
        $netInvesting = $this->computeNetFlow($investing);

        // Journal-based cash account movements for cross-validation
        $cashAccount     = Account::where('company_uuid', $companyUuid)->where('code', '1000')->first();
        $journalCashFlow = null;

        if ($cashAccount) {
            $openingBalance  = $this->getBalanceAtDate($cashAccount, now()->parse($startDate)->subDay()->toDateString());
            $closingBalance  = $this->getBalanceAtDate($cashAccount, $endDate);
            $journalCashFlow = [
                'opening_balance' => $openingBalance,
                'closing_balance' => $closingBalance,
                'net_change'      => $closingBalance - $openingBalance,
            ];
        }

        return [
            'period' => [
                'from' => $startDate,
                'to'   => $endDate,
            ],
            'operating_activities' => [
                'items'    => $operating,
                'net_flow' => $netOperating,
            ],
            'financing_activities' => [
                'items'    => $financing,
                'net_flow' => $netFinancing,
            ],
            'investing_activities' => [
                'items'    => $investing,
                'net_flow' => $netInvesting,
            ],
            'net_cash_change' => $netOperating + $netFinancing + $netInvesting,
            'cash_account'    => $journalCashFlow,
        ];
    }

    /**
     * Compute net flow (credits - debits) from a list of categorised wallet transaction rows.
     */
    protected function computeNetFlow(array $items): int
    {
        $net = 0;
        foreach ($items as $item) {
            if ($item['direction'] === 'credit') {
                $net += $item['total'];
            } else {
                $net -= $item['total'];
            }
        }

        return $net;
    }

    /**
     * Return owner/operator KPI tiles for the redesigned dashboard.
     */
    public function getDashboardSummary(string $companyUuid, ?string $startDate = null, ?string $endDate = null): array
    {
        $dashboard = $this->getDashboardMetrics($companyUuid, $startDate, $endDate);
        $currency  = $dashboard['currency'] ?? $this->resolveDashboardCurrency($dashboard['kpis']['wallet_totals'] ?? []);

        $outstandingAr = $dashboard['kpis']['outstanding_ar'] ?? ['total' => 0, 'overdue' => 0];
        $invoiceCounts = collect($dashboard['invoice_counts'] ?? []);
        $walletTotals  = collect($dashboard['kpis']['wallet_totals'] ?? []);
        $walletMetric  = $walletTotals->count() === 1 ? (int) ($walletTotals->first()['total'] ?? 0) : null;

        return [
            'period'   => $dashboard['period'],
            'currency' => $currency,
            'audit'    => $dashboard['audit'] ?? [],
            'metrics'  => [
                'total_revenue'  => $this->makeDashboardMetric('Total Revenue', $dashboard['kpis']['total_revenue'] ?? [], 'money', $currency),
                'total_expenses' => $this->makeDashboardMetric('Total Expenses', $dashboard['kpis']['total_expenses'] ?? [], 'money', $currency, true),
                'net_income'     => $this->makeDashboardMetric('Net Income', $dashboard['kpis']['net_income'] ?? [], 'money', $currency),
                'outstanding_ar' => [
                    'label'         => 'Outstanding AR',
                    'value'         => (int) ($outstandingAr['total'] ?? 0),
                    'previous'      => null,
                    'delta_percent' => null,
                    'format'        => 'money',
                    'currency'      => $currency,
                    'inverse'       => true,
                ],
                'overdue_ar' => [
                    'label'         => 'Overdue AR',
                    'value'         => (int) ($outstandingAr['overdue'] ?? 0),
                    'previous'      => null,
                    'delta_percent' => null,
                    'format'        => 'money',
                    'currency'      => $currency,
                    'inverse'       => true,
                ],
                'open_invoices' => [
                    'label'         => 'Open Invoices',
                    'value'         => (int) $invoiceCounts->except(['paid', 'cancelled', 'void'])->sum(),
                    'previous'      => null,
                    'delta_percent' => null,
                    'format'        => 'count',
                    'currency'      => $currency,
                    'inverse'       => true,
                ],
                'wallet_balance' => [
                    'label'          => 'Wallet Balance',
                    'value'          => $walletMetric,
                    'previous'       => null,
                    'delta_percent'  => null,
                    'format'         => 'money',
                    'currency'       => $walletTotals->count() === 1 ? $walletTotals->first()['currency'] : $currency,
                    'inverse'        => false,
                    'multi_currency' => $walletTotals->count() > 1,
                    'currencies'     => $walletTotals->values(),
                ],
                'active_wallets' => [
                    'label'         => 'Active Wallets',
                    'value'         => (int) $walletTotals->sum('count'),
                    'previous'      => null,
                    'delta_percent' => null,
                    'format'        => 'count',
                    'currency'      => $currency,
                    'inverse'       => false,
                ],
            ],
        ];
    }

    /**
     * Return revenue and expense trend rows for chart widgets.
     */
    public function getDashboardRevenueTrend(string $companyUuid, ?string $startDate = null, ?string $endDate = null): array
    {
        $startDate = $startDate ?? now()->subDays(29)->toDateString();
        $endDate   = $endDate ?? now()->toDateString();
        $activity  = $this->getProfitAndLossActivity($companyUuid, $startDate, $endDate);
        $points    = $activity['daily'];
        $revenue   = (int) $points->sum('revenue');
        $expenses  = (int) $points->sum('expenses');

        return [
            'period' => [
                'from' => $startDate,
                'to'   => $endDate,
            ],
            'currency' => $activity['currency'],
            'audit'    => $activity['audit'],
            'summary'  => [
                'revenue'  => $revenue,
                'expenses' => $expenses,
                'net'      => $revenue - $expenses,
            ],
            'labels'   => $points->pluck('date')->values(),
            'datasets' => [
                [
                    'label'           => 'Revenue',
                    'data'            => $points->pluck('revenue')->values(),
                    'borderColor'     => '#059669',
                    'backgroundColor' => 'rgba(5, 150, 105, 0.12)',
                    'tension'         => 0.35,
                    'fill'            => true,
                ],
                [
                    'label'           => 'Expenses',
                    'data'            => $points->pluck('expenses')->values(),
                    'borderColor'     => '#dc2626',
                    'backgroundColor' => 'rgba(220, 38, 38, 0.08)',
                    'tension'         => 0.35,
                    'fill'            => true,
                ],
            ],
        ];
    }

    /**
     * Return a condensed cash flow summary for the dashboard.
     */
    public function getDashboardCashFlowSummary(string $companyUuid, ?string $startDate = null, ?string $endDate = null): array
    {
        $cashFlow = $this->getCashFlowSummary($companyUuid, $startDate, $endDate);

        return [
            'period'            => $cashFlow['period'],
            'currency'          => $this->resolveDashboardCurrency(),
            'operating'         => (int) ($cashFlow['operating_activities']['net_flow'] ?? 0),
            'financing'         => (int) ($cashFlow['financing_activities']['net_flow'] ?? 0),
            'investing'         => (int) ($cashFlow['investing_activities']['net_flow'] ?? 0),
            'net_cash_change'   => (int) ($cashFlow['net_cash_change'] ?? 0),
            'cash_account'      => $cashFlow['cash_account'],
        ];
    }

    /**
     * Return invoice counts and balances by status.
     */
    public function getDashboardInvoiceStatus(string $companyUuid): array
    {
        $statuses = ['draft', 'sent', 'paid', 'overdue', 'cancelled', 'void'];
        $rows     = Invoice::where('company_uuid', $companyUuid)
            ->select('status', 'currency', DB::raw('count(*) as count'), DB::raw('sum(total_amount) as total'), DB::raw('sum(balance) as balance'))
            ->groupBy('status', 'currency')
            ->get();

        $summary = collect($statuses)->map(function ($status) use ($rows) {
            $matches = $rows->where('status', $status);

            return [
                'status'   => $status,
                'count'    => (int) $matches->sum('count'),
                'total'    => (int) $matches->sum('total'),
                'balance'  => (int) $matches->sum('balance'),
                'currency' => $matches->pluck('currency')->filter()->unique()->count() === 1 ? $matches->first()?->currency : null,
            ];
        })->values();

        return [
            'total_count' => (int) $summary->sum('count'),
            'total_open'  => (int) $summary->whereNotIn('status', ['paid', 'cancelled', 'void'])->sum('balance'),
            'summary'     => $summary,
        ];
    }

    /**
     * Return compact AR aging buckets for dashboard widgets.
     */
    public function getDashboardArAgingSummary(string $companyUuid, ?string $asOfDate = null): array
    {
        $aging = $this->getArAging($companyUuid, $asOfDate);

        return [
            'as_of_date'     => $aging['as_of_date'],
            'grand_total'    => $aging['grand_total'],
            'total_invoices' => $aging['total_invoices'],
            'buckets'        => collect($aging['buckets'])->map(fn ($bucket, $key) => [
                'key'           => $key,
                'label'         => $bucket['label'],
                'days_range'    => $bucket['days_range'],
                'total'         => (int) $bucket['total'],
                'invoice_count' => count($bucket['invoices'] ?? []),
            ])->values(),
        ];
    }

    /**
     * Return dashboard-ready wallet totals and top wallet rows.
     */
    public function getDashboardWalletBalances(string $companyUuid, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $dashboard = $this->getDashboardMetrics($companyUuid, $dateFrom, $dateTo);

        $topWallets = Wallet::where('company_uuid', $companyUuid)
            ->where('status', Wallet::STATUS_ACTIVE)
            ->with('subject')
            ->orderBy('balance', 'desc')
            ->limit(10)
            ->get()
            ->map(fn ($wallet) => [
                'wallet_public_id'  => $wallet->public_id,
                'name'              => $wallet->name,
                'type'              => $wallet->type,
                'balance'           => (int) $wallet->balance,
                'formatted_balance' => $wallet->formatted_balance,
                'currency'          => $wallet->currency,
                'subject'           => $wallet->subject ? [
                    'name' => $wallet->subject->name ?? $wallet->subject->email ?? $wallet->subject->public_id ?? $wallet->subject->uuid,
                ] : null,
            ]);

        return [
            'period'      => $dashboard['period'],
            'totals'      => $dashboard['kpis']['wallet_totals'] ?? [],
            'top_wallets' => $topWallets,
        ];
    }

    /**
     * Return recent financial activity for the dashboard feed.
     */
    public function getDashboardActivity(string $companyUuid, int $limit = 10): array
    {
        $journals = Journal::where('company_uuid', $companyUuid)
            ->with(['debitAccount', 'creditAccount'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(fn ($journal) => [
                'type'        => 'journal',
                'public_id'   => $journal->public_id,
                'description' => $journal->description,
                'amount'      => (int) $journal->amount,
                'currency'    => $journal->currency,
                'status'      => $journal->status,
                'created_at'  => $journal->created_at,
                'debit'       => $journal->debitAccount?->name,
                'credit'      => $journal->creditAccount?->name,
            ]);

        return [
            'items' => $journals,
        ];
    }

    protected function makeDashboardMetric(string $label, array $source, string $format, string $currency, bool $inverse = false): array
    {
        return [
            'label'         => $label,
            'value'         => (int) ($source['current'] ?? 0),
            'previous'      => isset($source['previous']) ? (int) $source['previous'] : null,
            'delta_percent' => $source['change_pct'] ?? null,
            'format'        => $format,
            'currency'      => $source['currency'] ?? $currency,
            'inverse'       => $inverse,
            'audit'         => $source['audit'] ?? null,
        ];
    }

    protected function resolveDashboardCurrency(iterable $walletTotals = []): string
    {
        foreach ($walletTotals as $row) {
            if (!empty($row['currency'])) {
                return $row['currency'];
            }
        }

        return 'USD';
    }

    // =========================================================================
    // Accounts Receivable Aging
    // =========================================================================

    /**
     * Generate an Accounts Receivable Aging Report.
     *
     * Buckets outstanding (unpaid) invoices by how many days past due they are:
     *   - Current       (not yet due or due today)
     *   - 1–30 days     overdue
     *   - 31–60 days    overdue
     *   - 61–90 days    overdue
     *   - 90+ days      overdue
     *
     * @param string|null $asOfDate ISO date string; defaults to today
     */
    public function getArAging(string $companyUuid, ?string $asOfDate = null): array
    {
        $asOfDate   = $asOfDate ?? now()->toDateString();
        $asOfCarbon = now()->parse($asOfDate);

        // Load all unpaid/partially-paid invoices
        $invoices = Invoice::where('company_uuid', $companyUuid)
            ->whereNotIn('status', ['paid', 'cancelled', 'void'])
            ->where('balance', '>', 0)
            ->with('customer')
            ->get();

        $buckets = [
            'current' => ['label' => 'Current',    'days_range' => '0',     'invoices' => [], 'total' => 0],
            '1_30'    => ['label' => '1–30 days',  'days_range' => '1-30',  'invoices' => [], 'total' => 0],
            '31_60'   => ['label' => '31–60 days', 'days_range' => '31-60', 'invoices' => [], 'total' => 0],
            '61_90'   => ['label' => '61–90 days', 'days_range' => '61-90', 'invoices' => [], 'total' => 0],
            'over_90' => ['label' => '90+ days',   'days_range' => '90+',   'invoices' => [], 'total' => 0],
        ];

        foreach ($invoices as $invoice) {
            $daysOverdue = 0;

            if ($invoice->due_date) {
                $daysOverdue = max(0, (int) $asOfCarbon->diffInDays($invoice->due_date, false) * -1);
            }

            $row = [
                'invoice_id'   => $invoice->public_id,
                'invoice_uuid' => $invoice->uuid,
                'number'       => $invoice->number,
                'customer'     => $invoice->customer ? [
                    'name' => $invoice->customer->name ?? $invoice->customer->public_id ?? null,
                ] : null,
                'date'         => $invoice->date?->toDateString(),
                'due_date'     => $invoice->due_date?->toDateString(),
                'total_amount' => $invoice->total_amount,
                'amount_paid'  => $invoice->amount_paid,
                'balance'      => $invoice->balance,
                'currency'     => $invoice->currency,
                'days_overdue' => $daysOverdue,
                'status'       => $invoice->status,
            ];

            if ($daysOverdue <= 0) {
                $buckets['current']['invoices'][] = $row;
                $buckets['current']['total'] += $invoice->balance;
            } elseif ($daysOverdue <= 30) {
                $buckets['1_30']['invoices'][] = $row;
                $buckets['1_30']['total'] += $invoice->balance;
            } elseif ($daysOverdue <= 60) {
                $buckets['31_60']['invoices'][] = $row;
                $buckets['31_60']['total'] += $invoice->balance;
            } elseif ($daysOverdue <= 90) {
                $buckets['61_90']['invoices'][] = $row;
                $buckets['61_90']['total'] += $invoice->balance;
            } else {
                $buckets['over_90']['invoices'][] = $row;
                $buckets['over_90']['total'] += $invoice->balance;
            }
        }

        $grandTotal = array_sum(array_column($buckets, 'total'));

        return [
            'as_of_date'     => $asOfDate,
            'buckets'        => $buckets,
            'grand_total'    => (int) $grandTotal,
            'total_invoices' => $invoices->count(),
        ];
    }

    // =========================================================================
    // Dashboard Metrics
    // =========================================================================

    /**
     * Get a comprehensive set of dashboard metrics for the Ledger overview page.
     *
     * Returns KPIs for the current period compared to the previous period:
     *   - Total revenue (current vs previous period, % change)
     *   - Total expenses (current vs previous period, % change)
     *   - Net income (current vs previous period, % change)
     *   - Outstanding AR (total + overdue)
     *   - Total wallet balances (by currency)
     *   - Invoice counts by status
     *   - Revenue trend (daily breakdown for the period)
     *   - Recent journal entries (last 10)
     *
     * @param string|null $startDate ISO date string; defaults to start of current month
     * @param string|null $endDate   ISO date string; defaults to today
     */
    public function getDashboardMetrics(string $companyUuid, ?string $startDate = null, ?string $endDate = null): array
    {
        $startDate = $startDate ?? now()->startOfMonth()->toDateString();
        $endDate   = $endDate ?? now()->toDateString();

        // Previous period (same length, immediately before)
        $periodDays    = now()->parse($startDate)->diffInDays(now()->parse($endDate)) + 1;
        $prevEndDate   = now()->parse($startDate)->subDay()->toDateString();
        $prevStartDate = now()->parse($prevEndDate)->subDays($periodDays - 1)->toDateString();

        // Income statements for current and previous period
        $currentIncome  = $this->getIncomeStatement($companyUuid, $startDate, $endDate);
        $previousIncome = $this->getIncomeStatement($companyUuid, $prevStartDate, $prevEndDate);

        // Outstanding AR
        $outstandingAr = Invoice::where('company_uuid', $companyUuid)
            ->whereNotIn('status', ['paid', 'cancelled', 'void'])
            ->where('balance', '>', 0)
            ->sum('balance');

        $overdueAr = Invoice::where('company_uuid', $companyUuid)
            ->whereNotIn('status', ['paid', 'cancelled', 'void'])
            ->where('balance', '>', 0)
            ->where('due_date', '<', now()->toDateString())
            ->sum('balance');

        // Invoice counts by status
        $invoiceCounts = Invoice::where('company_uuid', $companyUuid)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Wallet totals by currency
        $walletTotals = Wallet::where('company_uuid', $companyUuid)
            ->where('status', Wallet::STATUS_ACTIVE)
            ->select('currency', DB::raw('sum(balance) as total'), DB::raw('count(*) as count'))
            ->groupBy('currency')
            ->get()
            ->map(fn ($r) => [
                'currency' => $r->currency,
                'total'    => (int) $r->total,
                'count'    => (int) $r->count,
            ]);

        // Revenue trend — daily breakdown for the current period
        $revenueTrend = collect($currentIncome['daily'] ?? []);
        if ($revenueTrend->isEmpty()) {
            $revenueTrend = $this->getProfitAndLossActivity($companyUuid, $startDate, $endDate)['daily'];
        }

        $revenueTrend = $revenueTrend->map(fn ($r) => [
            'date'          => $r['date'],
            'daily_revenue' => (int) $r['revenue'],
        ]);

        // Recent journal entries
        $recentJournals = Journal::where('company_uuid', $companyUuid)
            ->with(['debitAccount', 'creditAccount'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return [
            'period' => [
                'from'          => $startDate,
                'to'            => $endDate,
                'previous_from' => $prevStartDate,
                'previous_to'   => $prevEndDate,
            ],
            'currency' => $currentIncome['currency'] ?? $this->resolveDashboardCurrency($walletTotals),
            'kpis'     => [
                'total_revenue' => [
                    'current'    => $currentIncome['total_revenue'],
                    'previous'   => $previousIncome['total_revenue'],
                    'change_pct' => $this->percentageChange($previousIncome['total_revenue'], $currentIncome['total_revenue']),
                    'currency'   => $currentIncome['currency'],
                    'audit'      => $currentIncome['audit'],
                ],
                'total_expenses' => [
                    'current'    => $currentIncome['total_expenses'],
                    'previous'   => $previousIncome['total_expenses'],
                    'change_pct' => $this->percentageChange($previousIncome['total_expenses'], $currentIncome['total_expenses']),
                    'currency'   => $currentIncome['currency'],
                    'audit'      => $currentIncome['audit'],
                ],
                'net_income' => [
                    'current'    => $currentIncome['net_income'],
                    'previous'   => $previousIncome['net_income'],
                    'change_pct' => $this->percentageChange($previousIncome['net_income'], $currentIncome['net_income']),
                    'profitable' => $currentIncome['net_income'] >= 0,
                    'currency'   => $currentIncome['currency'],
                    'audit'      => $currentIncome['audit'],
                ],
                'outstanding_ar' => [
                    'total'   => (int) $outstandingAr,
                    'overdue' => (int) $overdueAr,
                ],
                'wallet_totals' => $walletTotals,
            ],
            'invoice_counts'  => $invoiceCounts,
            'revenue_trend'   => $revenueTrend,
            'recent_journals' => $recentJournals,
            'audit'           => [
                'income_statement' => $currentIncome['audit'],
                'previous_period'  => $previousIncome['audit'],
            ],
        ];
    }

    /**
     * Calculate percentage change between two values.
     *
     * @return float|null returns null if previous is zero (undefined)
     */
    protected function percentageChange(int|float $previous, int|float $current): ?float
    {
        if ($previous == 0) {
            return $current > 0 ? 100.0 : null;
        }

        return round((($current - $previous) / abs($previous)) * 100, 2);
    }
}
