<?php

namespace Fleetbase\Ledger\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

/**
 * Transaction Resource.
 *
 * Serializes the Ledger Transaction model (which extends the core-api Transaction)
 * for Ledger API responses.
 *
 * Includes:
 *   - All core transaction fields
 *   - Extended Ledger fields: direction, polymorphic roles, monetary details,
 *     payment method, failure info, reporting, and traceability
 *   - Eagerly-loaded relationships: items, journal (with debit/credit accounts),
 *     subject, payer, payee, initiator, context
 */
class Transaction extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     */
    public function toArray($request): array
    {
        return [
            // ── Identifiers ────────────────────────────────────────────────
            'id'                          => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                        => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'                   => $this->when(Http::isInternalRequest(), $this->public_id),
            'company_uuid'                => $this->when(Http::isInternalRequest(), $this->company_uuid),

            // ── Legacy owner fields (core-api compat) ──────────────────────
            'owner_uuid'                  => $this->when(Http::isInternalRequest(), $this->owner_uuid),
            'owner_type'                  => $this->when(Http::isInternalRequest(), $this->owner_type),
            'customer_uuid'               => $this->when(Http::isInternalRequest(), $this->customer_uuid),
            'customer_type'               => $this->when(Http::isInternalRequest(), $this->customer_type),

            // ── Polymorphic subject (the resource this transaction is about) ─
            'subject_uuid'                => $this->when(Http::isInternalRequest(), $this->subject_uuid),
            'subject_type'                => $this->when(Http::isInternalRequest(), $this->subject_type),
            'subject'                     => $this->whenLoaded('subject', fn () => $this->resolvePolymorphicResource($this->subject)),

            // ── Polymorphic payer ──────────────────────────────────────────
            'payer_uuid'                  => $this->when(Http::isInternalRequest(), $this->payer_uuid),
            'payer_type'                  => $this->when(Http::isInternalRequest(), $this->payer_type),
            'payer'                       => $this->whenLoaded('payer', fn () => $this->resolvePolymorphicResource($this->payer)),
            'payer_name'                  => $this->resolveDisplayName($this->whenLoaded('payer', fn () => $this->payer)),

            // ── Polymorphic payee ──────────────────────────────────────────
            'payee_uuid'                  => $this->when(Http::isInternalRequest(), $this->payee_uuid),
            'payee_type'                  => $this->when(Http::isInternalRequest(), $this->payee_type),
            'payee'                       => $this->whenLoaded('payee', fn () => $this->resolvePolymorphicResource($this->payee)),
            'payee_name'                  => $this->resolveDisplayName($this->whenLoaded('payee', fn () => $this->payee)),

            // ── Polymorphic initiator ──────────────────────────────────────
            'initiator_uuid'              => $this->when(Http::isInternalRequest(), $this->initiator_uuid),
            'initiator_type'              => $this->when(Http::isInternalRequest(), $this->initiator_type),
            'initiator'                   => $this->whenLoaded('initiator', fn () => $this->resolvePolymorphicResource($this->initiator)),
            'initiator_name'              => $this->resolveDisplayName($this->whenLoaded('initiator', fn () => $this->initiator)),

            // ── Polymorphic context (related resource: Order, Invoice, etc.) ─
            'context_uuid'                => $this->when(Http::isInternalRequest(), $this->context_uuid),
            'context_type'                => $this->when(Http::isInternalRequest(), $this->context_type),
            'context'                     => $this->whenLoaded('context', fn () => $this->resolvePolymorphicResource($this->context)),

            // ── Gateway ────────────────────────────────────────────────────
            'gateway_transaction_id'      => $this->gateway_transaction_id,
            'gateway'                     => $this->gateway,
            'gateway_uuid'                => $this->when(Http::isInternalRequest(), $this->gateway_uuid),

            // ── Classification ─────────────────────────────────────────────
            'type'                        => $this->type,
            'direction'                   => $this->direction,
            'status'                      => $this->status,

            // ── Monetary ───────────────────────────────────────────────────
            'amount'                      => $this->amount,
            'fee_amount'                  => $this->fee_amount,
            'tax_amount'                  => $this->tax_amount,
            'net_amount'                  => $this->net_amount,
            'balance_after'               => $this->balance_after,
            'currency'                    => $this->currency,
            'exchange_rate'               => $this->exchange_rate,
            'settled_currency'            => $this->settled_currency,
            'settled_amount'              => $this->settled_amount,

            // ── Payment method ─────────────────────────────────────────────
            'payment_method'              => $this->payment_method,
            'payment_method_last4'        => $this->payment_method_last4,
            'payment_method_brand'        => $this->payment_method_brand,

            // ── Idempotency and linkage ────────────────────────────────────
            'reference'                   => $this->reference,
            'parent_transaction_uuid'     => $this->when(Http::isInternalRequest(), $this->parent_transaction_uuid),

            // ── Descriptive ────────────────────────────────────────────────
            'description'                 => $this->description,
            'notes'                       => $this->notes,

            // ── Failure info ───────────────────────────────────────────────
            'failure_reason'              => $this->failure_reason,
            'failure_code'                => $this->failure_code,

            // ── Reporting ──────────────────────────────────────────────────
            'period'                      => $this->period,
            'tags'                        => $this->tags,

            // ── Traceability ───────────────────────────────────────────────
            'ip_address'                  => $this->ip_address,

            // ── Metadata ───────────────────────────────────────────────────
            'meta'                        => $this->meta,

            // ── Relationships ──────────────────────────────────────────────
            'items'                       => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'uuid'     => $item->uuid,
                'amount'   => $item->amount,
                'currency' => $item->currency,
                'details'  => $item->details,
                'code'     => $item->code,
                'meta'     => $item->meta,
            ])),
            'journal'                     => $this->whenLoaded('journal', fn () => new Journal($this->journal)),

            // ── Timestamps ─────────────────────────────────────────────────
            'settled_at'                  => $this->settled_at,
            'voided_at'                   => $this->voided_at,
            'reversed_at'                 => $this->reversed_at,
            'expires_at'                  => $this->expires_at,
            'created_at'                  => $this->created_at,
            'updated_at'                  => $this->updated_at,
        ];
    }

    /**
     * Attempt to resolve a display name from a polymorphic relationship model.
     * Tries common name fields in order: name, display_name, full_name, email.
     */
    private function resolveDisplayName($model): ?string
    {
        if (!$model || !is_object($model)) {
            return null;
        }

        foreach (['name', 'display_name', 'full_name', 'email'] as $field) {
            if (!empty($model->{$field})) {
                return $model->{$field};
            }
        }

        return null;
    }

    /**
     * Resolve a polymorphic model to a simple identifier array.
     * Returns uuid + public_id + type so the frontend can link to the resource.
     */
    private function resolvePolymorphicResource($model): ?array
    {
        if (!$model || !is_object($model)) {
            return null;
        }

        return array_filter([
            'uuid'      => $model->uuid ?? null,
            'public_id' => $model->public_id ?? null,
            'type'      => class_basename($model),
            'name'      => $this->resolveDisplayName($model),
        ]);
    }
}
