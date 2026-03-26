<?php

namespace Fleetbase\Ledger\Models;

use Fleetbase\Models\Transaction as BaseTransaction;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Ledger Transaction model.
 *
 * Extends the core-api Transaction model to add:
 *   - Journal entry relationship
 *   - Polymorphic roles: subject, payer, payee, initiator, context
 *   - Extended fillable columns added by migration 000016
 */
class Transaction extends BaseTransaction
{
    /**
     * Additional fillable attributes beyond what the core model declares.
     * Merged with parent $fillable in the constructor.
     */
    protected $ledgerFillable = [
        'direction',
        'subject_uuid',
        'subject_type',
        'payer_uuid',
        'payer_type',
        'payee_uuid',
        'payee_type',
        'initiator_uuid',
        'initiator_type',
        'context_uuid',
        'context_type',
        'fee_amount',
        'tax_amount',
        'net_amount',
        'balance_after',
        'exchange_rate',
        'settled_currency',
        'settled_amount',
        'payment_method',
        'payment_method_last4',
        'payment_method_brand',
        'reference',
        'parent_transaction_uuid',
        'notes',
        'failure_reason',
        'failure_code',
        'period',
        'tags',
        'ip_address',
        'settled_at',
        'voided_at',
        'reversed_at',
        'expires_at',
    ];

    /**
     * Merge ledger-specific fillable fields into the parent's list.
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->fillable = array_unique(array_merge($this->fillable, $this->ledgerFillable));
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * The journal entry associated with this transaction, if one exists.
     */
    public function journal(): HasOne
    {
        return $this->hasOne(Journal::class, 'transaction_uuid', 'uuid');
    }

    /**
     * The line-item breakdown for this transaction.
     * Inherited from the core model but explicitly declared here for eager-loading.
     */
    public function items(): HasMany
    {
        return $this->hasMany(\Fleetbase\Models\TransactionItem::class, 'transaction_uuid', 'uuid');
    }

    /**
     * The polymorphic resource this transaction is about (e.g. Order, Invoice).
     */
    public function subject(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_uuid')->withoutGlobalScopes();
    }

    /**
     * The entity that paid (e.g. Customer, User, Driver).
     */
    public function payer(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'payer_type', 'payer_uuid')->withoutGlobalScopes();
    }

    /**
     * The entity that received the payment (e.g. Company, Driver).
     */
    public function payee(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'payee_type', 'payee_uuid')->withoutGlobalScopes();
    }

    /**
     * The entity that initiated / triggered this transaction (e.g. User, System).
     */
    public function initiator(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'initiator_type', 'initiator_uuid')->withoutGlobalScopes();
    }

    /**
     * The contextual resource linked to this transaction (e.g. Order, Invoice, Shipment).
     */
    public function context(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'context_type', 'context_uuid')->withoutGlobalScopes();
    }
}
