<?php

namespace Fleetbase\Ledger\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Casts\PolymorphicType;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Wallet.
 *
 * Represents a digital wallet belonging to any subject in the Fleetbase system.
 * Wallets are polymorphic — they can belong to a Driver, Customer, or Company.
 *
 * Balance is always stored as an integer in the smallest currency unit (cents).
 * Every balance change MUST produce a corresponding Transaction record.
 *
 * Wallet types (inferred from subject_type):
 *   - driver   : Earnings wallet for FleetOps drivers
 *   - customer : Prepaid credit wallet for Storefront customers
 *   - company  : Company operating wallet (e.g., for payouts)
 *
 * Statuses:
 *   - active  : Normal operation
 *   - frozen  : No debits allowed (credits still accepted)
 *   - closed  : No operations allowed
 *
 * @property string $uuid
 * @property string $public_id
 * @property string $company_uuid
 * @property string $subject_uuid
 * @property string $subject_type
 * @property int    $balance      Balance in smallest currency unit (cents)
 * @property string $currency
 * @property string $status       'active', 'frozen', 'closed'
 * @property string $name
 * @property string $description
 * @property bool   $is_frozen
 * @property array  $meta
 */
class Wallet extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use HasMetaAttributes;
    use TracksApiCredential;
    use Searchable;
    use SoftDeletes;

    /**
     * The database table used by the model.
     */
    protected $table = 'ledger_wallets';

    /**
     * The type of public Id to generate.
     */
    protected $publicIdType = 'wallet';

    /**
     * The response payload key to use.
     */
    protected $payloadKey = 'wallet';

    /**
     * The attributes that can be queried.
     */
    protected $searchableColumns = ['public_id', 'name'];

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        '_key',
        'public_id',
        'company_uuid',
        'created_by_uuid',
        'updated_by_uuid',
        'subject_uuid',
        'subject_type',
        'name',
        'description',
        'balance',
        'currency',
        'status',
        'is_frozen',
        'meta',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected $casts = [
        'balance'      => 'integer',
        'is_frozen'    => 'boolean',
        'subject_type' => PolymorphicType::class,
        'meta'         => Json::class,
    ];

    /**
     * Dynamic attributes that are appended to object.
     */
    protected $appends = ['public_id', 'type', 'formatted_balance'];

    // -------------------------------------------------------------------------
    // Status Constants
    // -------------------------------------------------------------------------

    public const STATUS_ACTIVE = 'active';
    public const STATUS_FROZEN = 'frozen';
    public const STATUS_CLOSED = 'closed';

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * The subject (owner) of this wallet.
     * Uses 'subject' naming convention per Fleetbase standards.
     *
     * Can be: Driver, Customer, Company, or any other polymorphic subject.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_uuid')->withoutGlobalScopes();
    }

    /**
     * All transactions on this wallet.
     * Transactions are linked via owner_uuid / owner_type on the core Transaction model.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'owner_uuid', 'uuid')
            ->orderBy('created_at', 'desc');
    }

    /**
     * Completed transactions only.
     */
    public function completedTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'owner_uuid', 'uuid')
            ->where('status', 'completed')
            ->orderBy('created_at', 'desc');
    }

    /**
     * Credit transactions only (money in).
     */
    public function credits(): HasMany
    {
        return $this->hasMany(Transaction::class, 'owner_uuid', 'uuid')
            ->where('direction', 'credit')
            ->where('status', 'completed');
    }

    /**
     * Debit transactions only (money out).
     */
    public function debits(): HasMany
    {
        return $this->hasMany(Transaction::class, 'owner_uuid', 'uuid')
            ->where('direction', 'debit')
            ->where('status', 'completed');
    }

    // -------------------------------------------------------------------------
    // Computed Attributes
    // -------------------------------------------------------------------------

    /**
     * Infer the wallet type from the subject_type.
     * Returns 'driver', 'customer', 'company', or 'unknown'.
     */
    public function getTypeAttribute(): string
    {
        if (!$this->subject_type) {
            return 'unknown';
        }

        $type = strtolower(class_basename($this->subject_type));

        return match (true) {
            str_contains($type, 'driver')   => 'driver',
            str_contains($type, 'customer') => 'customer',
            str_contains($type, 'company')  => 'company',
            str_contains($type, 'user')     => 'user',
            default                         => $type,
        };
    }

    /**
     * Get the balance formatted as a decimal string.
     * e.g., 1050 cents → "10.50".
     */
    public function getFormattedBalanceAttribute(): string
    {
        return number_format($this->balance / 100, 2);
    }

    // -------------------------------------------------------------------------
    // Status Helpers
    // -------------------------------------------------------------------------

    /**
     * Check if the wallet is active.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Check if the wallet is frozen.
     */
    public function isFrozen(): bool
    {
        return $this->status === self::STATUS_FROZEN;
    }

    /**
     * Check if the wallet is closed.
     */
    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    /**
     * Check if the wallet can accept debits (withdrawals, transfers out).
     * Frozen wallets can still receive credits.
     */
    public function canDebit(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Check if the wallet can accept credits (deposits, transfers in).
     */
    public function canCredit(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_FROZEN], true);
    }

    /**
     * Check if the wallet has sufficient balance for a given amount.
     *
     * @param int $amount Amount in smallest currency unit (cents)
     */
    public function hasSufficientBalance(int $amount): bool
    {
        return $this->balance >= $amount;
    }

    // -------------------------------------------------------------------------
    // State Transitions
    // -------------------------------------------------------------------------

    /**
     * Freeze the wallet. Debits are blocked; credits are still accepted.
     */
    public function freeze(): void
    {
        $this->update(['status' => self::STATUS_FROZEN]);
    }

    /**
     * Activate (unfreeze) the wallet.
     */
    public function activate(): void
    {
        $this->update(['status' => self::STATUS_ACTIVE]);
    }

    /**
     * Close the wallet. No operations are allowed.
     */
    public function close(): void
    {
        $this->update(['status' => self::STATUS_CLOSED]);
    }

    // -------------------------------------------------------------------------
    // Balance Operations (low-level — use WalletService for full lifecycle)
    // -------------------------------------------------------------------------

    /**
     * Credit the wallet balance by the given amount and return the new balance.
     * This method ONLY updates the balance column. Callers MUST also create
     * a Transaction record to maintain the audit trail.
     *
     * @param int $amount Amount in smallest currency unit (cents)
     *
     * @return int New balance
     */
    public function credit(int $amount): int
    {
        $this->increment('balance', $amount);
        $this->refresh();

        return $this->balance;
    }

    /**
     * Debit the wallet balance by the given amount and return the new balance.
     * This method ONLY updates the balance column. Callers MUST also create
     * a Transaction record to maintain the audit trail.
     *
     * @param int $amount Amount in smallest currency unit (cents)
     *
     * @return int New balance
     *
     * @throws \RuntimeException if the wallet has insufficient balance
     */
    public function debit(int $amount): int
    {
        if (!$this->hasSufficientBalance($amount)) {
            throw new \RuntimeException("Insufficient wallet balance. Available: {$this->balance}, Required: {$amount}.");
        }

        $this->decrement('balance', $amount);
        $this->refresh();

        return $this->balance;
    }

    // -------------------------------------------------------------------------
    // Static Factories
    // -------------------------------------------------------------------------

    /**
     * Find or create a wallet for a given subject.
     *
     * @param string $subjectType Fully-qualified class name
     */
    public static function forSubject(
        string $subjectUuid,
        string $subjectType,
        string $companyUuid,
        string $currency = 'USD',
    ): static {
        return static::firstOrCreate(
            [
                'subject_uuid' => $subjectUuid,
                'subject_type' => $subjectType,
            ],
            [
                'company_uuid' => $companyUuid,
                'balance'      => 0,
                'currency'     => $currency,
                'status'       => self::STATUS_ACTIVE,
            ]
        );
    }
}
