<?php

namespace Fleetbase\Ledger\Services;

use Fleetbase\Ledger\Models\Account;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Models\Journal;
use Fleetbase\Ledger\Models\Wallet;
use Fleetbase\Ledger\Models\Transaction;
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
     * Create a double-entry journal entry and the corresponding core Transaction record.
     *
     * Every financial movement in Ledger is represented as a pair of records:
     *   1. A `Transaction` (core-api primitive) — the canonical, auditable money-movement record.
     *   2. A `Journal` (Ledger model) — the double-entry bookkeeping entry that links the
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
            $type        = $options['type'] ?? 'ledger';
            $status      = $options['status'] ?? 'completed';
            $meta        = $options['meta'] ?? [];

            // Build the Transaction payload
            $transactionPayload = [
                'company_uuid' => $companyUuid,
                'amount'       => $amount,
                'currency'     => $currency,
                'description'  => $description,
                'type'         => $type,
                'status'       => $status,
                'meta'         => $meta,
            ];

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

            // Create the double-entry Journal record
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
     * Normal balance rules:
     *   - Asset & Expense:               balance = debits - credits  (debit-normal)
     *   - Liability, Equity & Revenue:   balance = credits - debits  (credit-normal)
     */
    public function getBalanceAtDate(Account $account, string $date): int
    {
        $debits  = Journal::where('debit_account_uuid', $account->uuid)
            ->where('date', '<=', $date)
            ->sum('amount');

        $credits = Journal::where('credit_account_uuid', $account->uuid)
            ->where('date', '<=', $date)
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
            ->where('is_active', true)
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
            ->where('is_active', true)
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

        $accounts = Account::where('company_uuid', $companyUuid)
            ->where('is_active', true)
            ->whereIn('type', [Account::TYPE_REVENUE, Account::TYPE_EXPENSE])
            ->orderBy('code')
            ->get();

        $revenues = [];
        $expenses = [];

        foreach ($accounts as $account) {
            // Income statement uses only activity within the period
            $debits  = Journal::where('debit_account_uuid', $account->uuid)
                ->whereBetween('date', [$startDate, $endDate])
                ->sum('amount');

            $credits = Journal::where('credit_account_uuid', $account->uuid)
                ->whereBetween('date', [$startDate, $endDate])
                ->sum('amount');

            if ($account->type === Account::TYPE_REVENUE) {
                $balance = (int) ($credits - $debits); // credit-normal
            } else {
                $balance = (int) ($debits - $credits); // debit-normal
            }

            if ($balance === 0) {
                continue;
            }

            $row = [
                'uuid'    => $account->uuid,
                'code'    => $account->code,
                'name'    => $account->name,
                'balance' => $balance,
            ];

            if ($account->type === Account::TYPE_REVENUE) {
                $revenues[] = $row;
            } else {
                $expenses[] = $row;
            }
        }

        $totalRevenue  = array_sum(array_column($revenues, 'balance'));
        $totalExpenses = array_sum(array_column($expenses, 'balance'));
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
        ];
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
        $revenueTrend = Journal::where('company_uuid', $companyUuid)
            ->whereHas('creditAccount', fn ($q) => $q->where('type', Account::TYPE_REVENUE))
            ->whereBetween('date', [$startDate, $endDate])
            ->select('date', DB::raw('sum(amount) as daily_revenue'))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => [
                'date'          => $r->date,
                'daily_revenue' => (int) $r->daily_revenue,
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
            'kpis' => [
                'total_revenue' => [
                    'current'    => $currentIncome['total_revenue'],
                    'previous'   => $previousIncome['total_revenue'],
                    'change_pct' => $this->percentageChange($previousIncome['total_revenue'], $currentIncome['total_revenue']),
                ],
                'total_expenses' => [
                    'current'    => $currentIncome['total_expenses'],
                    'previous'   => $previousIncome['total_expenses'],
                    'change_pct' => $this->percentageChange($previousIncome['total_expenses'], $currentIncome['total_expenses']),
                ],
                'net_income' => [
                    'current'    => $currentIncome['net_income'],
                    'previous'   => $previousIncome['net_income'],
                    'change_pct' => $this->percentageChange($previousIncome['net_income'], $currentIncome['net_income']),
                    'profitable' => $currentIncome['net_income'] >= 0,
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
