<?php

namespace Fleetbase\Ledger\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;
use Fleetbase\Support\Utils;

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
        $isInternal = Http::isInternalRequest();

        return [
            'id'               => $this->when($isInternal, $this->id, $this->public_id),
            'uuid'             => $this->when($isInternal, $this->uuid),
            'public_id'        => $this->when($isInternal, $this->public_id),
            'company_uuid'     => $this->when($isInternal, $this->company_uuid),
            'customer_uuid'    => $this->when($isInternal, $this->customer_uuid),
            'customer_type'    => $this->when($isInternal, $this->customer_type ? Utils::toEmberResourceType($this->customer_type) : null),
            'customer'         => $this->whenLoaded('customer', function () {
                return $this->setCustomerType($this->transformMorphResource($this->customer));
            }),
            'order_uuid'       => $this->when($isInternal, $this->order_uuid),
            'order'            => $this->whenLoaded('order'),
            'transaction_uuid' => $this->when($isInternal, $this->transaction_uuid),
            'template_uuid'    => $this->when($isInternal, $this->template_uuid),
            'template'         => $this->whenLoaded('template'),
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
            'items'            => InvoiceItem::collection($this->whenLoaded('items')),
            'meta'             => $this->meta,
            'sent_at'          => $this->sent_at,
            'viewed_at'        => $this->viewed_at,
            'paid_at'          => $this->paid_at,
            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,
        ];
    }

    /**
     * Stamp type='customer' and customer_type='customer-{ember-type}' onto the
     * resolved customer data array, matching the FleetOps Order resource pattern.
     *
     * @param array|null $resolved
     *
     * @return array|null
     */
    public function setCustomerType($resolved)
    {
        if (empty($resolved)) {
            return $resolved;
        }

        data_set($resolved, 'type', 'customer');
        data_set($resolved, 'customer_type', 'customer-' . Utils::toEmberResourceType($this->customer_type));

        return $resolved;
    }

    /**
     * Resolve a polymorphic relationship model into its appropriate HTTP resource array.
     * Uses Find::httpResourceForModel() to pick the registered resource class, falling
     * back to a generic JsonResource if none is found.
     *
     * @param \Illuminate\Database\Eloquent\Model|null $model
     *
     * @return array|null
     */
    protected function transformMorphResource($model)
    {
        if (!$model) {
            return null;
        }

        $resourceClass = \Fleetbase\Support\Find::httpResourceForModel($model);

        if ($resourceClass) {
            return (new $resourceClass($model))->resolve();
        }

        return (new \Illuminate\Http\Resources\Json\JsonResource($model))->resolve();
    }
}
