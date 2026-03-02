<?php

namespace Fleetbase\Ledger\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class Wallet extends FleetbaseResource
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
            'id'                => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'              => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'         => $this->when(Http::isInternalRequest(), $this->public_id),
            'company_uuid'      => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'subject_uuid'      => $this->subject_uuid,
            'subject_type'      => $this->subject_type,
            'subject'           => $this->whenLoaded('subject'),
            'name'              => $this->name,
            'description'       => $this->description,
            'type'              => $this->type,
            'balance'           => $this->balance,
            'formatted_balance' => $this->formatted_balance,
            'currency'          => $this->currency,
            'status'            => $this->status,
            'is_frozen'         => $this->is_frozen,
            'meta'              => $this->meta,
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
        ];
    }
}
