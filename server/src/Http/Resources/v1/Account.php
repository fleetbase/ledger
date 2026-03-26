<?php

namespace Fleetbase\Ledger\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class Account extends FleetbaseResource
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
            'name'              => $this->name,
            'code'              => $this->code,
            'type'              => $this->type,
            'description'       => $this->description,
            'is_system_account' => $this->is_system_account,
            'balance'           => $this->balance,
            'currency'          => $this->currency,
            'status'            => $this->status,
            'meta'              => $this->meta,
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
        ];
    }
}
