<?php

namespace Fleetbase\Ledger\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

/**
 * Gateway API Resource
 *
 * Serializes the Gateway model for API responses.
 * The config (credentials) field is intentionally excluded to prevent
 * accidental exposure of API keys and secrets.
 *
 * Internal requests (console) receive uuid as the id field.
 * Public API requests receive public_id as the id field.
 *
 * @package Fleetbase\Ledger\Http\Resources\v1
 */
class Gateway extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id'           => $this->when(Http::isInternalRequest(), $this->uuid, $this->public_id),
            'uuid'         => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'    => $this->when(Http::isInternalRequest(), $this->public_id),
            'company_uuid' => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'name'         => $this->name,
            'driver'       => $this->driver,
            'description'  => $this->description,
            'capabilities' => $this->capabilities ?? [],
            'is_sandbox'   => $this->is_sandbox,
            'status'       => $this->status,
            'return_url'   => $this->return_url,
            'webhook_url'  => $this->getWebhookUrl(),
            'created_at'   => $this->created_at,
            'updated_at'   => $this->updated_at,
        ];
    }
}
