<?php

namespace Fleetbase\Ledger\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

/**
 * WalletTransaction Resource
 *
 * Serializes a WalletTransaction for API responses.
 * Internal requests receive full detail including UUIDs.
 * Public requests receive a safe subset.
 *
 * @package Fleetbase\Ledger\Http\Resources\v1
 */
class WalletTransaction extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'id'               => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'             => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'        => $this->public_id,
            'company_uuid'     => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'wallet_uuid'      => $this->when(Http::isInternalRequest(), $this->wallet_uuid),
            'wallet'           => $this->whenLoaded('wallet'),
            'type'             => $this->type,
            'direction'        => $this->direction,
            'status'           => $this->status,
            'amount'           => $this->amount,
            'formatted_amount' => $this->formatted_amount,
            'balance_after'    => $this->balance_after,
            'currency'         => $this->currency,
            'description'      => $this->description,
            'reference'        => $this->reference,
            'subject_uuid'     => $this->when(Http::isInternalRequest(), $this->subject_uuid),
            'subject_type'     => $this->when(Http::isInternalRequest(), $this->subject_type),
            'subject'          => $this->whenLoaded('subject'),
            'meta'             => $this->meta,
            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,
        ];
    }
}
