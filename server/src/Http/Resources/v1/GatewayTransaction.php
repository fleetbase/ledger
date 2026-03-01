<?php

namespace Fleetbase\Ledger\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class GatewayTransaction extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'id'                     => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                   => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'              => $this->when(Http::isInternalRequest(), $this->public_id),
            'company_uuid'           => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'gateway_uuid'           => $this->when(Http::isInternalRequest(), $this->gateway_uuid),
            'gateway'                => $this->whenLoaded('gateway', fn () => new Gateway($this->gateway)),
            'gateway_transaction_id' => $this->gateway_transaction_id,
            'type'                   => $this->type,
            'status'                 => $this->status,
            'amount'                 => $this->amount,
            'currency'               => $this->currency,
            'description'            => $this->description,
            'customer_uuid'          => $this->when(Http::isInternalRequest(), $this->customer_uuid),
            'invoice_uuid'           => $this->when(Http::isInternalRequest(), $this->invoice_uuid),
            'meta'                   => $this->meta,
            'created_at'             => $this->created_at,
            'updated_at'             => $this->updated_at,
        ];
    }
}
