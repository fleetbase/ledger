<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Carbon\Carbon;
use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Ledger\Models\PayFileSchedule;
use Fleetbase\Ledger\Services\PayFileGeneratorService;

class PayFileScheduleController extends FleetbaseController
{
    public $resource = PayFileSchedule::class;

    /**
     * POST /pay-file-schedules/{id}/run-now
     */
    public function runNow(string $id)
    {
        $schedule = PayFileSchedule::findRecordOrFail($id);

        $start = $schedule->last_run_at ? $schedule->last_run_at : now()->subDays(30);
        $end = now();

        $payFile = app(PayFileGeneratorService::class)->generate(
            $schedule->company_uuid,
            $schedule->format,
            Carbon::parse($start),
            $end
        );

        $schedule->update([
            'last_run_at' => now(),
            'next_run_at' => $schedule->calculateNextRun(now()),
        ]);

        return response()->json(['data' => $payFile->load('items')]);
    }
}
