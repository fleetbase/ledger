<?php

namespace Fleetbase\Ledger\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class ServiceAgreement extends FleetbaseResource
{
    public function toArray($request)
    {
        return [
            'id'                => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'              => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'         => $this->when(Http::isInternalRequest(), $this->public_id),
            'customer_uuid'     => $this->when(Http::isInternalRequest(), $this->customer_uuid),
            'name'              => $this->name,
            'status'            => $this->status,
            'billing_frequency' => $this->billing_frequency,
            'payment_terms_days'=> $this->payment_terms_days,
            'effective_date'    => $this->effective_date,
            'expiration_date'   => $this->expiration_date,
            'currency'          => $this->currency,
            'notes'             => $this->notes,
            'charges'           => $this->whenLoaded('charges'),
            'meta'              => $this->meta,
            'updated_at'        => $this->updated_at,
            'created_at'        => $this->created_at,
        ];
    }
}
