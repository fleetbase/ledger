<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Ledger\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
