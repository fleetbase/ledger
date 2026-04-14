<?php
namespace Fleetbase\Ledger\Http\Resources\v1;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class PayFile extends FleetbaseResource
{
    public function toArray($request)
    {
        return [
            'id'           => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'         => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'    => $this->when(Http::isInternalRequest(), $this->public_id),
            'name'         => $this->name,
            'format'       => $this->format,
            'status'       => $this->status,
            'period_start' => $this->period_start,
            'period_end'   => $this->period_end,
            'record_count' => $this->record_count,
            'total_amount' => $this->total_amount,
            'generated_at' => $this->generated_at,
            'sent_at'      => $this->sent_at,
            'confirmed_at' => $this->confirmed_at,
            'items'        => $this->whenLoaded('items'),
            'file'         => $this->whenLoaded('file'),
            'meta'         => $this->meta,
            'created_at'   => $this->created_at,
        ];
    }
}
