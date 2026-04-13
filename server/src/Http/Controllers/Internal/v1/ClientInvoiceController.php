<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Carbon\Carbon;
use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Ledger\Models\ClientInvoice;
use Fleetbase\Ledger\Models\ServiceAgreement;
use Fleetbase\Ledger\Services\BatchInvoiceService;
use Fleetbase\Ledger\Services\ClientInvoiceGeneratorService;
use Illuminate\Http\Request;

/**
 * Thin controller for client invoice management.
 * Billing calculation logic lives in ClientInvoiceGeneratorService and BatchInvoiceService.
 */
class ClientInvoiceController extends FleetbaseController
{
    public $resource = ClientInvoice::class;

    /**
     * POST /client-invoices/generate
     * Generate a client invoice for a specific shipment + service agreement.
     */
    public function generate(Request $request)
    {
        $validated = $request->validate([
            'shipment_uuid'          => 'required|string',
            'service_agreement_uuid' => 'required|string',
        ]);

        $shipment = \Fleetbase\FleetOps\Models\Shipment::where('uuid', $validated['shipment_uuid'])
            ->where('company_uuid', session('company'))
            ->firstOrFail();

        $agreement = ServiceAgreement::where('uuid', $validated['service_agreement_uuid'])
            ->where('company_uuid', session('company'))
            ->firstOrFail();

        $invoice = app(ClientInvoiceGeneratorService::class)
            ->generateForShipment($shipment, $agreement);

        return response()->json(['data' => $invoice]);
    }

    /**
     * POST /client-invoices/batch-generate
     * Generate batch invoices for a billing period.
     */
    public function batchGenerate(Request $request)
    {
        $validated = $request->validate([
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after_or_equal:period_start',
        ]);

        $invoices = app(BatchInvoiceService::class)->generateBatch(
            session('company'),
            Carbon::parse($validated['period_start']),
            Carbon::parse($validated['period_end'])
        );

        return response()->json([
            'data'  => $invoices,
            'count' => $invoices->count(),
        ]);
    }
}
