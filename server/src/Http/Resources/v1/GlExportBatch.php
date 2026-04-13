<?php

namespace Fleetbase\Ledger\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class GlExportBatch extends FleetbaseResource
{
    public function toArray($request)
    {
        return [
            'id'            => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'          => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'     => $this->when(Http::isInternalRequest(), $this->public_id),
            'format'        => $this->format,
            'status'        => $this->status,
            'period_start'  => $this->period_start,
            'period_end'    => $this->period_end,
            'record_count'  => $this->record_count,
            'total_amount'  => $this->total_amount,
            'exported_at'   => $this->exported_at,
            'meta'          => $this->meta,
            'created_at'    => $this->created_at,
        ];
    }
}
