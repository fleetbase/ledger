<?php

namespace Fleetbase\Ledger\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Account extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use HasMetaAttributes;
    use TracksApiCredential;
    use Searchable;
    use SoftDeletes;

    /**
     * Account type constants.
     */
    const TYPE_ASSET     = 'asset';
    const TYPE_LIABILITY = 'liability';
    const TYPE_EQUITY    = 'equity';
    const TYPE_REVENUE   = 'revenue';
    const TYPE_EXPENSE   = 'expense';

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'ledger_accounts';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'account';

    /**
     * The attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['name', 'code', 'description'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        '_key',
        'public_id',
        'company_uuid',
        'created_by_uuid',
        'updated_by_uuid',
        'name',
        'code',
        'type',
        'description',
        'is_system_account',
        'balance',
        'currency',
        'status',
        'meta',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'is_system_account' => 'boolean',
        'balance'           => 'integer',
        'meta'              => Json::class,
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = [];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [];

    /**
     * Journal entries where this account is debited.
     */
    public function debitJournals(): HasMany
    {
        return $this->hasMany(Journal::class, 'debit_account_uuid');
    }

    /**
     * Journal entries where this account is credited.
     */
    public function creditJournals(): HasMany
    {
        return $this->hasMany(Journal::class, 'credit_account_uuid');
    }

    /**
     * All journal entries for this account.
     */
    public function journals()
    {
        return Journal::where('debit_account_uuid', $this->uuid)
            ->orWhere('credit_account_uuid', $this->uuid);
    }

    /**
     * Calculate the current balance of the account.
     */
    public function calculateBalance(): int
    {
        $debits  = $this->debitJournals()->sum('amount');
        $credits = $this->creditJournals()->sum('amount');

        // For asset and expense accounts, debits increase balance
        // For liability, equity, and revenue accounts, credits increase balance
        if (in_array($this->type, ['asset', 'expense'])) {
            return $debits - $credits;
        }

        return $credits - $debits;
    }

    /**
     * Update the cached balance.
     */
    public function updateBalance(): void
    {
        $this->balance = $this->calculateBalance();
        $this->save();
    }

    /**
     * Check if the account is an asset.
     */
    public function isAsset(): bool
    {
        return $this->type === 'asset';
    }

    /**
     * Check if the account is a liability.
     */
    public function isLiability(): bool
    {
        return $this->type === 'liability';
    }

    /**
     * Check if the account is equity.
     */
    public function isEquity(): bool
    {
        return $this->type === 'equity';
    }

    /**
     * Check if the account is revenue.
     */
    public function isRevenue(): bool
    {
        return $this->type === 'revenue';
    }

    /**
     * Check if the account is an expense.
     */
    public function isExpense(): bool
    {
        return $this->type === 'expense';
    }
}
