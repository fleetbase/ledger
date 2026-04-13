<?php

namespace Fleetbase\Ledger\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class ClientInvoice extends FleetbaseResource
{
    public function toArray($request)
    {
        return [
            'id'                     => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                   => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'              => $this->when(Http::isInternalRequest(), $this->public_id),
            'customer_uuid'          => $this->when(Http::isInternalRequest(), $this->customer_uuid),
            'service_agreement_uuid' => $this->when(Http::isInternalRequest(), $this->service_agreement_uuid),
            'shipment_uuid'          => $this->when(Http::isInternalRequest(), $this->shipment_uuid),
            'invoice_number'         => $this->invoice_number,
            'status'                 => $this->status,
            'subtotal'               => $this->subtotal,
            'tax_amount'             => $this->tax_amount,
            'total_amount'           => $this->total_amount,
            'invoice_date'           => $this->invoice_date,
            'due_date'               => $this->due_date,
            'period_start'           => $this->period_start,
            'period_end'             => $this->period_end,
            'currency'               => $this->currency,
            'notes'                  => $this->notes,
            'items'                  => $this->whenLoaded('items'),
            'service_agreement'      => $this->whenLoaded('serviceAgreement'),
            'meta'                   => $this->meta,
            'sent_at'                => $this->sent_at,
            'paid_at'                => $this->paid_at,
            'updated_at'             => $this->updated_at,
            'created_at'             => $this->created_at,
        ];
    }
}
