<?php

namespace Fleetbase\Ledger\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class GatewayTransaction extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     */
    public function toArray($request): array
    {
        return [
            'id'                     => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                   => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'              => $this->when(Http::isInternalRequest(), $this->public_id),
            'company_uuid'           => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'gateway_uuid'           => $this->when(Http::isInternalRequest(), $this->gateway_uuid),
            'gateway'                => $this->whenLoaded('gateway', fn () => new Gateway($this->gateway)),
            'gateway_reference_id'   => $this->gateway_reference_id,
            'gateway_transaction_id' => $this->gateway_reference_id,
            'event_type'             => $this->event_type,
            'type'                   => $this->type,
            'status'                 => $this->status,
            'message'                => $this->message,
            'amount'                 => $this->amount,
            'currency'               => $this->currency,
            'transaction_uuid'       => $this->when(Http::isInternalRequest(), $this->transaction_uuid),
            'processed_at'           => $this->processed_at,
            'refund_status'          => $this->refund_status,
            'refund_accepted_at'     => $this->refund_accepted_at,
            'refund_expires_at'      => $this->refund_expires_at,
            'reconciliation_status'     => $this->reconciliation_status,
            'reconciliation_checked_at' => $this->reconciliation_checked_at,
            'reconciliation_data'       => $this->when(Http::isInternalRequest(), $this->reconciliation_data),
            'raw_response'              => $this->when(Http::isInternalRequest(), $this->raw_response),
            'created_at'             => $this->created_at,
            'updated_at'             => $this->updated_at,
        ];
    }
}
