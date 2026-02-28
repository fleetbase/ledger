<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Ledger\Models\Wallet;
use Fleetbase\Ledger\Models\WalletTransaction;
use Fleetbase\Ledger\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * The ledger service instance.
     *
     * @var LedgerService
     */
    protected LedgerService $ledgerService;

    /**
     * Create a new ReportController instance.
     *
     * @param LedgerService $ledgerService
     */
    public function __construct(LedgerService $ledgerService)
    {
        $this->ledgerService = $ledgerService;
    }

    /**
     * Generate a trial balance report for the authenticated company.
     *
     * The trial balance lists every active account with its debit and credit
     * totals as of a given date. The sum of all debit-normal balances must
     * equal the sum of all credit-normal balances for the books to be balanced.
     *
     * Query parameters:
     *   - as_of_date  (string, optional)  ISO date string; defaults to today.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function trialBalance(Request $request): JsonResponse
    {
        $request->validate([
            'as_of_date' => 'nullable|date',
        ]);

        $companyUuid = session('company');
        $asOfDate    = $request->input('as_of_date');

        $trialBalance = $this->ledgerService->getTrialBalance($companyUuid, $asOfDate);

        return response()->json($trialBalance);
    }

    /**
     * Generate a wallet summary report for the authenticated company.
     *
     * Returns aggregate statistics across all wallets:
     *   - Total wallets by type (driver, customer, company)
     *   - Total balance by currency
     *   - Total credits and debits in a given period
     *   - Top earners (drivers by earnings)
     *   - Recent transactions
     *
     * Query parameters:
     *   - date_from  (string, optional)  ISO date string; defaults to start of current month.
     *   - date_to    (string, optional)  ISO date string; defaults to today.
     *
     * @param Request $request
     *
     * @return JsonResponse
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

        // Total wallets grouped by inferred type
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
        $periodStats = WalletTransaction::where('company_uuid', $companyUuid)
            ->where('status', WalletTransaction::STATUS_COMPLETED)
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
                    if ($row->direction === WalletTransaction::DIRECTION_CREDIT) {
                        $result['credits']       = (int) $row->total;
                        $result['credit_count']  = (int) $row->count;
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
                'wallet_public_id' => $w->public_id,
                'balance'          => $w->balance,
                'formatted_balance' => $w->formatted_balance,
                'currency'         => $w->currency,
                'subject'          => $w->subject ? [
                    'name' => $w->subject->name ?? $w->subject->public_id ?? $w->subject->uuid,
                ] : null,
            ]);

        return response()->json([
            'period' => [
                'from' => $dateFrom,
                'to'   => $dateTo,
            ],
            'wallet_counts'     => $walletCounts,
            'period_stats'      => $periodStats,
            'top_driver_wallets' => $topDriverWallets,
        ]);
    }
}
