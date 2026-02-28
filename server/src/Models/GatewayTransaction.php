<?php

namespace Fleetbase\Ledger\Models;

use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;

/**
 * GatewayTransaction Model
 *
 * Audit log and idempotency record for all interactions with payment gateways.
 * Every purchase, refund, and webhook event is recorded here.
 *
 * The gateway_reference_id is the unique identifier from the gateway itself
 * (e.g., Stripe's pi_xxx or ch_xxx, QPay's invoice_id). This is used as the
 * idempotency key to prevent duplicate processing of webhook events.
 *
 * @property string      $uuid
 * @property string      $public_id
 * @property string      $company_uuid
 * @property string      $gateway_uuid
 * @property string|null $transaction_uuid
 * @property string|null $gateway_reference_id
 * @property string      $type
 * @property string|null $event_type
 * @property int|null    $amount
 * @property string|null $currency
 * @property string      $status
 * @property string|null $message
 * @property array|null  $raw_response
 * @property \Carbon\Carbon|null $processed_at
 *
 * @package Fleetbase\Ledger\Models
 */
class GatewayTransaction extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;

    /**
     * The database table used by the model.
     */
    protected $table = 'ledger_gateway_transactions';

    /**
     * The public ID prefix for this model.
     */
    protected $publicIdPrefix = 'gtxn';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'uuid',
        'public_id',
        'company_uuid',
        'gateway_uuid',
        'transaction_uuid',
        'gateway_reference_id',
        'type',
        'event_type',
        'amount',
        'currency',
        'status',
        'message',
        'raw_response',
        'processed_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'raw_response' => 'array',
        'amount'       => 'integer',
        'processed_at' => 'datetime',
    ];

    /**
     * The attributes that should be appended to the model's array form.
     */
    protected $appends = ['public_id'];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * The gateway this transaction belongs to.
     */
    public function gateway()
    {
        return $this->belongsTo(Gateway::class, 'gateway_uuid', 'uuid');
    }

    /**
     * The core-api Transaction record linked to this gateway transaction.
     */
    public function transaction()
    {
        return $this->belongsTo(\Fleetbase\Models\Transaction::class, 'transaction_uuid', 'uuid');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * Scope to find a transaction by gateway reference ID (idempotency check).
     */
    public function scopeForGatewayReference($query, string $referenceId)
    {
        return $query->where('gateway_reference_id', $referenceId);
    }

    /**
     * Scope to filter by type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to filter by status.
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Check if this transaction has already been processed.
     * Used for idempotency in webhook handling.
     *
     * @return bool
     */
    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }

    /**
     * Mark this transaction as processed.
     *
     * @return bool
     */
    public function markAsProcessed(): bool
    {
        return $this->update(['processed_at' => now()]);
    }

    /**
     * Check if a gateway reference ID has already been processed (static idempotency check).
     *
     * @param string $gatewayReferenceId The gateway's own transaction ID
     * @param string $type               The transaction type (e.g., 'webhook_event')
     *
     * @return bool
     */
    public static function alreadyProcessed(string $gatewayReferenceId, string $type = 'webhook_event'): bool
    {
        return static::where('gateway_reference_id', $gatewayReferenceId)
            ->where('type', $type)
            ->whereNotNull('processed_at')
            ->exists();
    }
}
