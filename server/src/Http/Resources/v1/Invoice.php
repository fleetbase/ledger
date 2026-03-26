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
            // ── Identifiers ────────────────────────────────────────────────
            'id'               => $this->when($isInternal, $this->id, $this->public_id),
            'uuid'             => $this->when($isInternal, $this->uuid),
            'public_id'        => $this->public_id,
            // ── Ownership ──────────────────────────────────────────────────
            'company_uuid'     => $this->when($isInternal, $this->company_uuid),
            'created_by_uuid'  => $this->when($isInternal, $this->created_by_uuid),
            'updated_by_uuid'  => $this->when($isInternal, $this->updated_by_uuid),
            // ── Customer (polymorphic) ──────────────────────────────────────
            'customer_uuid'    => $this->when($isInternal, $this->customer_uuid),
            'customer_type'    => $this->when($isInternal, $this->customer_type ? Utils::toEmberResourceType($this->customer_type) : null),
            'customer'         => $this->whenLoaded('customer', function () {
                return $this->setCustomerType($this->transformMorphResource($this->customer));
            }),
            // ── Related records ────────────────────────────────────────────
            'order_uuid'       => $this->when($isInternal, $this->order_uuid),
            'order'            => $this->whenLoaded('order'),
            'transaction_uuid' => $this->when($isInternal, $this->transaction_uuid),
            'transaction'      => $this->whenLoaded('transaction'),
            'template_uuid'    => $this->when($isInternal, $this->template_uuid),
            'template'         => $this->whenLoaded('template'),
            // ── Invoice details ────────────────────────────────────────────
            'number'           => $this->number,
            'status'           => $this->status,
            'date'             => $this->date,
            'due_date'         => $this->due_date,
            // ── Monetary (all values in smallest currency unit / cents) ────
            'currency'         => $this->currency,
            'subtotal'         => $this->subtotal,
            'tax'              => $this->tax,
            'total_amount'     => $this->total_amount,
            'amount_paid'      => $this->amount_paid,
            'balance'          => $this->balance,
            // ── Content ────────────────────────────────────────────────────
            'notes'            => $this->notes,
            'terms'            => $this->terms,
            // ── Line items ─────────────────────────────────────────────────
            'items'            => InvoiceItem::collection($this->whenLoaded('items')),
            // ── Metadata ───────────────────────────────────────────────────
            'meta'             => $this->meta,
            // ── Timestamps ─────────────────────────────────────────────────
            'sent_at'          => $this->sent_at,
            'viewed_at'        => $this->viewed_at,
            'paid_at'          => $this->paid_at,
            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,
        ];
    }

    /**
     * Stamp type='customer' and customer_type onto the resolved customer data array.
     *
     * customer_type is the full Ember resource type string, e.g. "fleet-ops:vendor"
     * or "fleet-ops:contact".  We do NOT prefix with "customer-" because
     * Utils::toEmberResourceType() already returns the full namespaced type and
     * prepending "customer-" would produce the invalid string
     * "customer-fleet-ops:vendor".
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
        data_set($resolved, 'customer_type', Utils::toEmberResourceType($this->customer_type));

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
