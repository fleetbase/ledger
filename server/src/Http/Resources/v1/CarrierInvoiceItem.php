<?php

namespace Fleetbase\Ledger\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class CarrierInvoiceItem extends FleetbaseResource
{
    public function toArray($request)
    {
        return [
            'id'                 => $this->when(Http::isInternalRequest(), $this->id, $this->uuid),
            'uuid'               => $this->when(Http::isInternalRequest(), $this->uuid),
            'charge_type'        => $this->charge_type,
            'description'        => $this->description,
            'accessorial_code'   => $this->accessorial_code,
            'invoiced_amount'    => $this->invoiced_amount,
            'planned_amount'     => $this->planned_amount,
            'approved_amount'    => $this->approved_amount,
            'discrepancy_amount' => $this->discrepancy_amount,
            'quantity'           => $this->quantity,
            'rate'               => $this->rate,
            'rate_type'          => $this->rate_type,
            'meta'               => $this->meta,
            'created_at'         => $this->created_at,
        ];
    }
}
