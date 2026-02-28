<?php

namespace Fleetbase\Ledger\Models;

use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * WalletTransaction
 *
 * Records every individual debit or credit against a wallet.
 * This provides a complete, immutable audit trail of all wallet movements.
 *
 * Every balance change on a Wallet MUST produce a WalletTransaction record.
 * The wallet's current balance is always the sum of all its transactions.
 *
 * Transaction types:
 *   - deposit       : Funds added from an external source (gateway top-up, manual credit)
 *   - withdrawal    : Funds removed to an external destination (bank payout, manual debit)
 *   - transfer_in   : Funds received from another wallet within the system
 *   - transfer_out  : Funds sent to another wallet within the system
 *   - payout        : Earnings paid out to a driver
 *   - fee           : Platform or gateway fee deducted
 *   - refund        : Refund credited back to the wallet
 *   - adjustment    : Manual balance correction by an operator
 *   - earning       : Earnings credited to a driver wallet after order completion
 *
 * @property string      $uuid
 * @property string      $public_id
 * @property string      $wallet_uuid
 * @property string      $type
 * @property int         $amount            Amount in smallest currency unit (cents)
 * @property int         $balance_after     Wallet balance after this transaction (cents)
 * @property string      $currency
 * @property string      $direction         'credit' or 'debit'
 * @property string      $status            'pending', 'completed', 'failed', 'reversed'
 * @property string|null $description
 * @property string|null $reference         External reference (gateway transaction ID, order ID, etc.)
 * @property string|null $subject_uuid      Polymorphic subject (driver, customer, order, invoice)
 * @property string|null $subject_type
 * @property string|null $meta              JSON metadata
 *
 * @package Fleetbase\Ledger\Models
 */
class WalletTransaction extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use SoftDeletes;

    /**
     * The database table used by the model.
     */
    protected $table = 'ledger_wallet_transactions';

    /**
     * The public ID prefix.
     */
    protected string $publicIdPrefix = 'wallet_txn';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'uuid',
        'public_id',
        'company_uuid',
        'wallet_uuid',
        'gateway_transaction_uuid',
        'type',
        'amount',
        'balance_after',
        'currency',
        'direction',
        'status',
        'description',
        'reference',
        'subject_uuid',
        'subject_type',
        'meta',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'amount'       => 'integer',
        'balance_after' => 'integer',
        'meta'         => 'array',
    ];

    /**
     * The attributes that should be appended.
     */
    protected $appends = ['public_id'];

    // -------------------------------------------------------------------------
    // Transaction Type Constants
    // -------------------------------------------------------------------------

    public const TYPE_DEPOSIT      = 'deposit';
    public const TYPE_WITHDRAWAL   = 'withdrawal';
    public const TYPE_TRANSFER_IN  = 'transfer_in';
    public const TYPE_TRANSFER_OUT = 'transfer_out';
    public const TYPE_PAYOUT       = 'payout';
    public const TYPE_FEE          = 'fee';
    public const TYPE_REFUND       = 'refund';
    public const TYPE_ADJUSTMENT   = 'adjustment';
    public const TYPE_EARNING      = 'earning';

    // -------------------------------------------------------------------------
    // Direction Constants
    // -------------------------------------------------------------------------

    public const DIRECTION_CREDIT = 'credit';
    public const DIRECTION_DEBIT  = 'debit';

    // -------------------------------------------------------------------------
    // Status Constants
    // -------------------------------------------------------------------------

    public const STATUS_PENDING   = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_REVERSED  = 'reversed';

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * The wallet this transaction belongs to.
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'wallet_uuid', 'uuid');
    }

    /**
     * The gateway transaction that triggered this wallet transaction (if any).
     */
    public function gatewayTransaction(): BelongsTo
    {
        return $this->belongsTo(GatewayTransaction::class, 'gateway_transaction_uuid', 'uuid');
    }

    /**
     * Polymorphic subject — the entity this transaction relates to.
     * Uses the 'subject' naming convention per Fleetbase standards.
     *
     * Can be: Driver, Customer, Order, Invoice, etc.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_uuid');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * Scope to credits only.
     */
    public function scopeCredits($query)
    {
        return $query->where('direction', self::DIRECTION_CREDIT);
    }

    /**
     * Scope to debits only.
     */
    public function scopeDebits($query)
    {
        return $query->where('direction', self::DIRECTION_DEBIT);
    }

    /**
     * Scope to completed transactions only.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope to a specific type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Get the amount formatted as a decimal string.
     * e.g., 1050 cents → "10.50"
     */
    public function getFormattedAmountAttribute(): string
    {
        return number_format($this->amount / 100, 2);
    }

    /**
     * Whether this transaction is a credit (money in).
     */
    public function isCredit(): bool
    {
        return $this->direction === self::DIRECTION_CREDIT;
    }

    /**
     * Whether this transaction is a debit (money out).
     */
    public function isDebit(): bool
    {
        return $this->direction === self::DIRECTION_DEBIT;
    }

    /**
     * Whether this transaction has completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
