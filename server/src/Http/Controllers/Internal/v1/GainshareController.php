<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Ledger\Models\CostBenchmark;
use Fleetbase\Ledger\Models\GainshareExecution;
use Fleetbase\Ledger\Models\GainshareRule;
use Fleetbase\Ledger\Services\GainshareCalculationService;
use Illuminate\Http\Request;

/**
 * Thin controller for gainshare management and reporting.
 * Calculation logic lives in GainshareCalculationService.
 */
class GainshareController extends FleetbaseController
{
    public $resource = GainshareExecution::class;

    /**
     * GET /gainshare/summary
     * Aggregate gainshare summary for a customer.
     */
    public function summary(Request $request)
    {
        $validated = $request->validate([
            'customer_uuid' => 'required|string',
            'days'          => 'nullable|integer|min:1|max:365',
        ]);

        $summary = app(GainshareCalculationService::class)->getCustomerSummary(
            session('company'),
            $validated['customer_uuid'],
            $validated['days'] ?? 90
        );

        return response()->json(['data' => $summary]);
    }
}
