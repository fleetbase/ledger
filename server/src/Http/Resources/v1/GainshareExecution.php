<?php

namespace Fleetbase\Ledger\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class GainshareExecution extends FleetbaseResource
{
    public function toArray($request)
    {
        return [
            'id'                   => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                 => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'            => $this->when(Http::isInternalRequest(), $this->public_id),
            'shipment_uuid'        => $this->when(Http::isInternalRequest(), $this->shipment_uuid),
            'carrier_invoice_uuid' => $this->when(Http::isInternalRequest(), $this->carrier_invoice_uuid),
            'client_invoice_uuid'  => $this->when(Http::isInternalRequest(), $this->client_invoice_uuid),
            'benchmark_total'      => $this->benchmark_total,
            'actual_total'         => $this->actual_total,
            'savings'              => $this->savings,
            'company_share'        => $this->company_share,
            'client_share'         => $this->client_share,
            'status'               => $this->status,
            'period_start'         => $this->period_start,
            'period_end'           => $this->period_end,
            'gainshare_rule'       => $this->whenLoaded('gainshareRule'),
            'shipment'             => $this->whenLoaded('shipment'),
            'cost_benchmark'       => $this->whenLoaded('costBenchmark'),
            'meta'                 => $this->meta,
            'created_at'           => $this->created_at,
        ];
    }
}
