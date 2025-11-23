<?php

namespace Fleetbase\Ledger\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class Invoice extends FleetbaseResource
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
            'id'               => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'             => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'        => $this->when(Http::isInternalRequest(), $this->public_id),
            'company_uuid'     => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'customer_uuid'    => $this->customer_uuid,
            'customer_type'    => $this->customer_type,
            'customer'         => $this->whenLoaded('customer'),
            'order_uuid'       => $this->order_uuid,
            'order'            => $this->whenLoaded('order'),
            'transaction_uuid' => $this->transaction_uuid,
            'number'           => $this->number,
            'date'             => $this->date,
            'due_date'         => $this->due_date,
            'subtotal'         => $this->subtotal,
            'tax'              => $this->tax,
            'total_amount'     => $this->total_amount,
            'amount_paid'      => $this->amount_paid,
            'balance'          => $this->balance,
            'currency'         => $this->currency,
            'status'           => $this->status,
            'notes'            => $this->notes,
            'terms'            => $this->terms,
            'items'            => $this->whenLoaded('items'),
            'meta'             => $this->meta,
            'sent_at'          => $this->sent_at,
            'viewed_at'        => $this->viewed_at,
            'paid_at'          => $this->paid_at,
            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,
        ];
    }
}
