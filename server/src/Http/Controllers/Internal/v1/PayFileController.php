<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Carbon\Carbon;
use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Ledger\Models\PayFile;
use Fleetbase\Ledger\Services\PayFileGeneratorService;
use Illuminate\Http\Request;

/**
 * Thin controller for pay file management.
 * Generation logic lives in PayFileGeneratorService.
 * Lifecycle transitions live on the PayFile model.
 */
class PayFileController extends FleetbaseController
{
    public $resource = PayFile::class;

    /**
     * POST /pay-files/generate
     */
    public function generate(Request $request)
    {
        $validated = $request->validate([
            'format'       => 'required|string|in:csv,edi_820,ach_nacha',
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after_or_equal:period_start',
        ]);

        $payFile = app(PayFileGeneratorService::class)->generate(
            session('company'),
            $validated['format'],
            Carbon::parse($validated['period_start']),
            Carbon::parse($validated['period_end'])
        );

        return response()->json(['data' => $payFile]);
    }

    /**
     * GET /pay-files/{id}/download
     */
    public function download(string $id)
    {
        $payFile = PayFile::findRecordOrFail($id);

        if (!$payFile->file_uuid) {
            return response()->apiError('Pay file has not been generated yet.', 404);
        }

        $file = \Fleetbase\Models\File::where('uuid', $payFile->file_uuid)->firstOrFail();

        return response()->download(
            storage_path('app/' . $file->path),
            $file->original_filename
        );
    }

    /**
     * POST /pay-files/{id}/mark-sent
     */
    public function markSent(string $id)
    {
        $payFile = PayFile::findRecordOrFail($id);

        try {
            $payFile->markAsSent();
        } catch (\RuntimeException $e) {
            return response()->apiError($e->getMessage());
        }

        return response()->json(['data' => $payFile->fresh()]);
    }

    /**
     * POST /pay-files/{id}/mark-confirmed
     * THIS endpoint is the ONLY way to flip carrier invoices to 'paid'.
     */
    public function markConfirmed(string $id)
    {
        $payFile = PayFile::findRecordOrFail($id);

        try {
            $confirmed = $payFile->markAsConfirmed();
        } catch (\RuntimeException $e) {
            return response()->apiError($e->getMessage());
        }

        return response()->json([
            'data'    => $confirmed->load('items'),
            'message' => "Pay file confirmed. {$confirmed->record_count} carrier invoices marked as paid.",
        ]);
    }

    /**
     * POST /pay-files/{id}/cancel
     */
    public function cancel(string $id)
    {
        $payFile = PayFile::findRecordOrFail($id);

        try {
            $payFile->cancel();
        } catch (\RuntimeException $e) {
            return response()->apiError($e->getMessage());
        }

        return response()->json(['data' => $payFile->fresh()]);
    }
}
