<?php

namespace Fleetbase\Ledger\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class InvoiceItem extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array
     */
    public function toArray($request)
    {
        $isInternal = Http::isInternalRequest();

        return [
            // ── Identifiers ────────────────────────────────────────────────
            'id'           => $this->when($isInternal, $this->id, $this->public_id),
            'uuid'         => $this->when($isInternal, $this->uuid),
            'public_id'    => $this->public_id,
            // ── Ownership ──────────────────────────────────────────────────
            'invoice_uuid' => $this->when($isInternal, $this->invoice_uuid),
            // ── Line item details ──────────────────────────────────────────
            'description'  => $this->description,
            'quantity'     => $this->quantity,
            // ── Monetary (all values in smallest currency unit / cents) ────
            'unit_price'   => $this->unit_price,
            'amount'       => $this->amount,
            'tax_rate'     => $this->tax_rate,   // percentage, e.g. 10.00 = 10%
            'tax_amount'   => $this->tax_amount,
            // ── Metadata ───────────────────────────────────────────────────
            'meta'         => $this->meta,
            // ── Timestamps ─────────────────────────────────────────────────
            'created_at'   => $this->created_at,
            'updated_at'   => $this->updated_at,
        ];
    }
}
