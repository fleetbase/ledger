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
        return [
            'id'           => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'         => $this->when(Http::isInternalRequest(), $this->uuid),
            'invoice_uuid' => $this->when(Http::isInternalRequest(), $this->invoice_uuid),
            'description'  => $this->description,
            'quantity'     => $this->quantity,
            'unit_price'   => $this->unit_price,
            'amount'       => $this->amount,
            'tax_rate'     => $this->tax_rate,
            'tax_amount'   => $this->tax_amount,
            'meta'         => $this->meta,
            'created_at'   => $this->created_at,
            'updated_at'   => $this->updated_at,
        ];
    }
}
