<?php

namespace Fleetbase\Ledger\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class GlAssignment extends FleetbaseResource
{
    public function toArray($request)
    {
        return [
            'id'               => $this->when(Http::isInternalRequest(), $this->id, $this->uuid),
            'uuid'             => $this->when(Http::isInternalRequest(), $this->uuid),
            'gl_account'       => $this->whenLoaded('glAccount'),
            'rule'             => $this->whenLoaded('rule'),
            'assignable_type'  => class_basename($this->assignable_type),
            'assignable_uuid'  => $this->when(Http::isInternalRequest(), $this->assignable_uuid),
            'amount'           => $this->amount,
            'assignment_type'  => $this->assignment_type,
            'meta'             => $this->meta,
            'created_at'       => $this->created_at,
        ];
    }
}
