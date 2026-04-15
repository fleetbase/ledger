<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Carbon\Carbon;
use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Ledger\Models\GlExportBatch;
use Fleetbase\Ledger\Services\GlExportService;
use Illuminate\Http\Request;

class GlExportBatchController extends FleetbaseController
{
    public $resource = GlExportBatch::class;

    public function generate(Request $request)
    {
        $validated = $request->validate([
            'format'       => 'required|string|in:csv,json,quickbooks_iif',
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after_or_equal:period_start',
        ]);

        $batch = app(GlExportService::class)->generateExport(
            session('company'),
            $validated['format'],
            Carbon::parse($validated['period_start']),
            Carbon::parse($validated['period_end'])
        );

        return response()->json(['data' => $batch]);
    }

    public function download(string $id)
    {
        $batch = GlExportBatch::findRecordOrFail($id);

        if (!$batch->file_uuid) {
            return response()->apiError('Export file not generated yet.', 404);
        }

        $file = \Fleetbase\Models\File::where('uuid', $batch->file_uuid)->firstOrFail();

        return response()->download(
            storage_path('app/' . $file->path),
            $file->original_name
        );
    }
}
