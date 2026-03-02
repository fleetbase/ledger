<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Ledger\Models\Wallet;
use Fleetbase\Ledger\Models\Transaction;
use Fleetbase\Ledger\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ReportController.
 *
 * Provides all financial reporting endpoints for the Ledger module.
 *
 * Available reports:
 *   - Dashboard Metrics   — KPIs, revenue trend, recent journals
 *   - Trial Balance       — All accounts with debit/credit totals
 *   - Balance Sheet       — Assets = Liabilities + Equity
 *   - Income Statement    — Revenue - Expenses = Net Income (P&L)
 *   - Cash Flow Summary   — Operating / Financing / Investing activities
 *   - AR Aging            — Outstanding invoices bucketed by days overdue
 *   - Wallet Summary      — Wallet counts, balances, and top wallets
 *
 * All monetary values are returned in the smallest currency unit (cents).
 * All date parameters accept ISO 8601 date strings (YYYY-MM-DD).
 */
class ReportController extends Controller
{
    /**
     * The ledger service instance.
     */
    protected LedgerService $ledgerService;

    /**
     * Create a new ReportController instance.
     */
    public function __construct(LedgerService $ledgerService)
    {
        $this->ledgerService = $ledgerService;
    }

    // =========================================================================
    // Dashboard
    // =========================================================================

    /**
     * GET /ledger/int/v1/reports/dashboard.
     *
     * Returns a comprehensive set of KPIs and metrics for the Ledger overview page.
     *
     * Query parameters:
     *   - start_date  (string, optional)  Period start date (YYYY-MM-DD); defaults to start of current month.
     *   - end_date    (string, optional)  Period end date (YYYY-MM-DD); defaults to today.
     *
     * Response includes:
     *   - period           Current and previous period dates
     *   - kpis             total_revenue, total_expenses, net_income (each with current/previous/change_pct),
     *                      outstanding_ar (total + overdue), wallet_totals (by currency)
     *   - invoice_counts   Counts grouped by status
     *   - revenue_trend    Daily revenue breakdown for the period
     *   - recent_journals  Last 10 journal entries
     */
    public function dashboard(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date',
        ]);

        $companyUuid = session('company');
        $startDate   = $request->input('start_date');
        $endDate     = $request->input('end_date');

        $metrics = $this->ledgerService->getDashboardMetrics($companyUuid, $startDate, $endDate);

        return response()->json([
            'status' => 'ok',
            'data'   => $metrics,
        ]);
    }

    // =========================================================================
    // Trial Balance
    // =========================================================================

    /**
     * GET /ledger/int/v1/reports/trial-balance.
     *
     * Returns a trial balance snapshot listing all active accounts with their
     * debit/credit balances as of a given date.
     *
     * Query parameters:
     *   - as_of_date  (string, optional)  ISO date string; defaults to today.
     *
     * Response includes:
     *   - accounts      Array of account rows with debit_total and credit_total
     *   - debit_total   Sum of all debit-normal balances
     *   - credit_total  Sum of all credit-normal balances
     *   - balanced      Boolean — true if debit_total === credit_total
     *   - as_of_date    The date used for the snapshot
     */
    public function trialBalance(Request $request): JsonResponse
    {
        $request->validate([
            'as_of_date' => 'nullable|date',
        ]);

        $companyUuid = session('company');
        $asOfDate    = $request->input('as_of_date');

        $report = $this->ledgerService->getTrialBalance($companyUuid, $asOfDate);

        return response()->json([
            'status' => 'ok',
            'data'   => $report,
        ]);
    }

    // =========================================================================
    // Balance Sheet
    // =========================================================================

    /**
     * GET /ledger/int/v1/reports/balance-sheet.
     *
     * Returns a Balance Sheet (Statement of Financial Position) as of a given date.
     *
     * Verifies the fundamental accounting equation:
     *   Assets = Liabilities + Equity
     *
     * Query parameters:
     *   - as_of_date  (string, optional)  ISO date string; defaults to today.
     *
     * Response includes:
     *   - assets                       Array of asset account rows
     *   - liabilities                  Array of liability account rows
     *   - equity                       Array of equity account rows
     *   - total_assets                 Sum of all asset balances
     *   - total_liabilities            Sum of all liability balances
     *   - total_equity                 Sum of all equity balances
     *   - total_liabilities_and_equity total_liabilities + total_equity
     *   - balanced                     Boolean — true if equation holds
     *   - as_of_date                   The date used for the snapshot
     */
    public function balanceSheet(Request $request): JsonResponse
    {
        $request->validate([
            'as_of_date' => 'nullable|date',
        ]);

        $companyUuid = session('company');
        $asOfDate    = $request->input('as_of_date');

        $report = $this->ledgerService->getBalanceSheet($companyUuid, $asOfDate);

        return response()->json([
            'status' => 'ok',
            'data'   => $report,
        ]);
    }

    // =========================================================================
    // Income Statement (P&L)
    // =========================================================================

    /**
     * GET /ledger/int/v1/reports/income-statement.
     *
     * Returns an Income Statement (Profit & Loss) for a given period.
     *
     * Query parameters:
     *   - start_date  (string, optional)  Period start date; defaults to start of current month.
     *   - end_date    (string, optional)  Period end date; defaults to today.
     *
     * Response includes:
     *   - period          from / to dates
     *   - revenues        Array of revenue account rows with period balance
     *   - expenses        Array of expense account rows with period balance
     *   - total_revenue   Sum of all revenue balances
     *   - total_expenses  Sum of all expense balances
     *   - net_income      total_revenue - total_expenses
     *   - profitable      Boolean — true if net_income >= 0
     */
    public function incomeStatement(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date',
        ]);

        $companyUuid = session('company');
        $startDate   = $request->input('start_date');
        $endDate     = $request->input('end_date');

        $report = $this->ledgerService->getIncomeStatement($companyUuid, $startDate, $endDate);

        return response()->json([
            'status' => 'ok',
            'data'   => $report,
        ]);
    }

    // =========================================================================
    // Cash Flow Summary
    // =========================================================================

    /**
     * GET /ledger/int/v1/reports/cash-flow.
     *
     * Returns a Cash Flow Summary for a given period, derived from wallet transactions
     * and cross-validated against the journal Cash account (code 1000).
     *
     * Query parameters:
     *   - start_date  (string, optional)  Period start date; defaults to start of current month.
     *   - end_date    (string, optional)  Period end date; defaults to today.
     *
     * Response includes:
     *   - period                  from / to dates
     *   - operating_activities    items[] + net_flow
     *   - financing_activities    items[] + net_flow
     *   - investing_activities    items[] + net_flow
     *   - net_cash_change         Sum of all three net flows
     *   - cash_account            opening_balance, closing_balance, net_change (from journals)
     */
    public function cashFlow(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date',
        ]);

        $companyUuid = session('company');
        $startDate   = $request->input('start_date');
        $endDate     = $request->input('end_date');

        $report = $this->ledgerService->getCashFlowSummary($companyUuid, $startDate, $endDate);

        return response()->json([
            'status' => 'ok',
            'data'   => $report,
        ]);
    }

    // =========================================================================
    // AR Aging
    // =========================================================================

    /**
     * GET /ledger/int/v1/reports/ar-aging.
     *
     * Returns an Accounts Receivable Aging report bucketing all outstanding
     * invoices by how many days past due they are as of a given date.
     *
     * Buckets:
     *   - current   (not yet due or due today)
     *   - 1_30      (1–30 days overdue)
     *   - 31_60     (31–60 days overdue)
     *   - 61_90     (61–90 days overdue)
     *   - over_90   (90+ days overdue)
     *
     * Query parameters:
     *   - as_of_date  (string, optional)  ISO date string; defaults to today.
     *
     * Response includes:
     *   - as_of_date     The date used for the snapshot
     *   - buckets        Object keyed by bucket name, each with label, days_range, invoices[], total
     *   - grand_total    Sum of all outstanding balances
     *   - total_invoices Count of all outstanding invoices
     */
    public function arAging(Request $request): JsonResponse
    {
        $request->validate([
            'as_of_date' => 'nullable|date',
        ]);

        $companyUuid = session('company');
        $asOfDate    = $request->input('as_of_date');

        $report = $this->ledgerService->getArAging($companyUuid, $asOfDate);

        return response()->json([
            'status' => 'ok',
            'data'   => $report,
        ]);
    }

    // =========================================================================
    // Wallet Summary
    // =========================================================================

    /**
     * GET /ledger/int/v1/reports/wallet-summary.
     *
     * Returns a summary of all wallets in the system.
     *
     * Query parameters:
     *   - date_from  (string, optional)  Period start for transaction aggregation; defaults to start of current month.
     *   - date_to    (string, optional)  Period end; defaults to today.
     *
     * Response includes:
     *   - period                Period from/to dates
     *   - wallet_counts         Wallet counts grouped by owner type with total balances
     *   - period_stats          Credits and debits for the period grouped by currency
     *   - top_driver_wallets    Top 10 driver wallets by balance
     */
    public function walletSummary(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date',
        ]);

        $companyUuid = session('company');
        $dateFrom    = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo      = $request->input('date_to', now()->toDateString());

        // Total wallets grouped by inferred owner type
        $walletCounts = Wallet::where('company_uuid', $companyUuid)
            ->select('subject_type', DB::raw('count(*) as count'), DB::raw('sum(balance) as total_balance'), 'currency')
            ->groupBy('subject_type', 'currency')
            ->get()
            ->map(function ($row) {
                $type = strtolower(class_basename($row->subject_type ?? ''));

                return [
                    'type'          => match (true) {
                        str_contains($type, 'driver')   => 'driver',
                        str_contains($type, 'customer') => 'customer',
                        str_contains($type, 'company')  => 'company',
                        default                         => $type,
                    },
                    'count'         => (int) $row->count,
                    'total_balance' => (int) $row->total_balance,
                    'currency'      => $row->currency,
                ];
            });

        // Period credits and debits
        $periodStats = Transaction::where('company_uuid', $companyUuid)
            ->where('status', 'completed')
            ->whereBetween(DB::raw('DATE(created_at)'), [$dateFrom, $dateTo])
            ->select(
                'direction',
                'currency',
                DB::raw('count(*) as count'),
                DB::raw('sum(amount) as total')
            )
            ->groupBy('direction', 'currency')
            ->get()
            ->groupBy('currency')
            ->map(function ($rows) {
                $result = ['credits' => 0, 'debits' => 0, 'credit_count' => 0, 'debit_count' => 0];
                foreach ($rows as $row) {
                    if ($row->direction === 'credit') {
                        $result['credits']      = (int) $row->total;
                        $result['credit_count'] = (int) $row->count;
                    } else {
                        $result['debits']      = (int) $row->total;
                        $result['debit_count'] = (int) $row->count;
                    }
                }

                return $result;
            });

        // Top 10 driver wallets by balance
        $topDriverWallets = Wallet::where('company_uuid', $companyUuid)
            ->where('subject_type', 'like', '%Driver%')
            ->with('subject')
            ->orderBy('balance', 'desc')
            ->limit(10)
            ->get()
            ->map(fn ($w) => [
                'wallet_public_id'  => $w->public_id,
                'balance'           => $w->balance,
                'formatted_balance' => $w->formatted_balance,
                'currency'          => $w->currency,
                'subject'           => $w->subject ? [
                    'name' => $w->subject->name ?? $w->subject->public_id ?? $w->subject->uuid,
                ] : null,
            ]);

        return response()->json([
            'status' => 'ok',
            'data'   => [
                'period' => [
                    'from' => $dateFrom,
                    'to'   => $dateTo,
                ],
                'wallet_counts'      => $walletCounts,
                'period_stats'       => $periodStats,
                'top_driver_wallets' => $topDriverWallets,
            ],
        ]);
    }
}
