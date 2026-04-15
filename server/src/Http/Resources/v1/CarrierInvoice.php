<?php

namespace Fleetbase\Ledger\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class CarrierInvoice extends FleetbaseResource
{
    public function toArray($request)
    {
        return [
            'id'                  => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'           => $this->when(Http::isInternalRequest(), $this->public_id),
            'vendor_uuid'         => $this->when(Http::isInternalRequest(), $this->vendor_uuid),
            'order_uuid'          => $this->when(Http::isInternalRequest(), $this->order_uuid),
            'shipment_uuid'       => $this->when(Http::isInternalRequest(), $this->shipment_uuid),
            'invoice_number'      => $this->invoice_number,
            'pro_number'          => $this->pro_number,
            'bol_number'          => $this->bol_number,
            'source'              => $this->source,
            'status'              => $this->status,
            'invoiced_amount'     => $this->invoiced_amount,
            'planned_amount'      => $this->planned_amount,
            'approved_amount'     => $this->approved_amount,
            'discrepancy_amount'  => $this->discrepancy_amount,
            'discrepancy_percent' => $this->discrepancy_percent,
            'discrepancy_type'    => $this->discrepancy_type,
            'resolution'          => $this->resolution,
            'resolution_notes'    => $this->resolution_notes,
            'invoice_date'        => $this->invoice_date,
            'due_date'            => $this->due_date,
            'pickup_date'         => $this->pickup_date,
            'delivery_date'       => $this->delivery_date,
            'currency'            => $this->currency,
            'vendor'              => $this->whenLoaded('vendor'),
            'order'               => $this->whenLoaded('order'),
            'items'               => CarrierInvoiceItem::collection($this->whenLoaded('items')),
            'resolved_by'         => $this->whenLoaded('resolvedBy'),
            'meta'                => $this->meta,
            'received_at'         => $this->received_at,
            'resolved_at'         => $this->resolved_at,
            'updated_at'          => $this->updated_at,
            'created_at'          => $this->created_at,
        ];
    }
}
