<?php

namespace Fleetbase\Ledger\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

/**
 * Transaction Resource.
 *
 * Serializes the Ledger Transaction model (which extends the core-api Transaction)
 * for Ledger API responses. The journal relationship is included when loaded.
 */
class Transaction extends FleetbaseResource
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
            'owner_uuid'             => $this->when(Http::isInternalRequest(), $this->owner_uuid),
            'owner_type'             => $this->when(Http::isInternalRequest(), $this->owner_type),
            'customer_uuid'          => $this->when(Http::isInternalRequest(), $this->customer_uuid),
            'customer_type'          => $this->when(Http::isInternalRequest(), $this->customer_type),
            'gateway_transaction_id' => $this->gateway_transaction_id,
            'gateway'                => $this->gateway,
            'gateway_uuid'           => $this->when(Http::isInternalRequest(), $this->gateway_uuid),
            'amount'                 => $this->amount,
            'currency'               => $this->currency,
            'description'            => $this->description,
            'type'                   => $this->type,
            'status'                 => $this->status,
            'meta'                   => $this->meta,
            'journal'                => $this->whenLoaded('journal', fn () => new Journal($this->journal)),
            'created_at'             => $this->created_at,
            'updated_at'             => $this->updated_at,
        ];
    }
}
