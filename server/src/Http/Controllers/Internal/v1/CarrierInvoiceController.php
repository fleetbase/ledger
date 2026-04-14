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

    /**
     * POST /carrier-invoices/batch-approve
     * Body: { "invoice_uuids": ["..."], "notes": "optional" }
     *
     * Bulk-approve audited invoices using the existing resolve() flow with
     * resolution='pay_invoiced'. Each invoice is resolved individually so
     * existing event-driven side effects (GL assignment, gainshare) fire
     * normally per invoice.
     */
    public function batchApprove(Request $request)
    {
        $validated = $request->validate([
            'invoice_uuids' => 'required|array|min:1',
            'notes'         => 'nullable|string|max:5000',
        ]);

        $service = app(CarrierInvoiceAuditService::class);
        $approved = [];
        $skipped  = [];

        foreach ($validated['invoice_uuids'] as $uuid) {
            $invoice = CarrierInvoice::where('uuid', $uuid)
                ->where('company_uuid', session('company'))
                ->first();

            if (!$invoice) {
                $skipped[] = ['uuid' => $uuid, 'reason' => 'not_found'];
                continue;
            }

            // Only approve invoices in audit-ready states
            if (!in_array($invoice->status, ['audited', 'in_review'])) {
                $skipped[] = ['uuid' => $uuid, 'reason' => "status_{$invoice->status}_not_eligible"];
                continue;
            }

            try {
                $service->resolve($invoice, 'pay_invoiced', null, $validated['notes'] ?? null);
                $approved[] = $uuid;
            } catch (\Throwable $e) {
                $skipped[] = ['uuid' => $uuid, 'reason' => substr($e->getMessage(), 0, 200)];
            }
        }

        return response()->json([
            'data' => [
                'approved'       => $approved,
                'approved_count' => count($approved),
                'skipped'        => $skipped,
                'skipped_count'  => count($skipped),
            ],
        ]);
    }
}
