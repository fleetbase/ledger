<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Ledger\Models\CarrierInvoice;
use Fleetbase\Ledger\Services\CarrierInvoiceAuditService;
use Illuminate\Http\Request;

class CarrierInvoiceController extends FleetbaseController
{
    public $resource = CarrierInvoice::class;

    public function audit(string $id)
    {
        $invoice = CarrierInvoice::findRecordOrFail($id);
        $audited = app(CarrierInvoiceAuditService::class)->audit($invoice);
        return response()->json(['data' => $audited->load('items')]);
    }

    public function resolve(string $id, Request $request)
    {
        $invoice = CarrierInvoice::findRecordOrFail($id);

        $validated = $request->validate([
            'resolution'    => 'required|string|in:pay_invoiced,pay_planned,pay_custom,disputed',
            'custom_amount' => 'nullable|numeric|min:0',
            'notes'         => 'nullable|string|max:5000',
        ]);

        $resolved = app(CarrierInvoiceAuditService::class)->resolve(
            $invoice,
            $validated['resolution'],
            $validated['custom_amount'] ?? null,
            $validated['notes'] ?? null
        );

        return response()->json(['data' => $resolved->load('items')]);
    }
}
