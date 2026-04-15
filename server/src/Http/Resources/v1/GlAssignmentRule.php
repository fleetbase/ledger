<?php

namespace Fleetbase\Ledger\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class GlAssignmentRule extends FleetbaseResource
{
    public function toArray($request)
    {
        return [
            'id'               => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'             => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'        => $this->when(Http::isInternalRequest(), $this->public_id),
            'name'             => $this->name,
            'priority'         => $this->priority,
            'match_type'       => $this->match_type,
            'gl_account_uuid'  => $this->when(Http::isInternalRequest(), $this->gl_account_uuid),
            'gl_account'       => $this->whenLoaded('glAccount'),
            'target'           => $this->target,
            'is_active'        => $this->is_active,
            'conditions'       => $this->whenLoaded('conditions'),
            'meta'             => $this->meta,
            'updated_at'       => $this->updated_at,
            'created_at'       => $this->created_at,
        ];
    }
}
