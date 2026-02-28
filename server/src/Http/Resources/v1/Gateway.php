<?php

namespace Fleetbase\Ledger\Http\Resources\v1;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Gateway API Resource
 *
 * Serializes the Gateway model for API responses.
 * The config (credentials) field is intentionally excluded.
 *
 * @package Fleetbase\Ledger\Http\Resources\v1
 */
class Gateway extends JsonResource
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
            'id'           => $this->public_id,
            'uuid'         => $this->uuid,
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
