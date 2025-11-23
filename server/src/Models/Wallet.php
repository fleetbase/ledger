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
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

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
     *
     * @var string
     */
    protected $table = 'ledger_wallets';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'wallet';

    /**
     * The attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['public_id'];

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
        'subject_uuid',
        'subject_type',
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
        'balance'      => 'integer',
        'subject_type' => PolymorphicType::class,
        'meta'         => Json::class,
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
     * The subject (owner) of this wallet.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_uuid')->withoutGlobalScopes();
    }

    /**
     * Check if the wallet is active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if the wallet is frozen.
     *
     * @return bool
     */
    public function isFrozen(): bool
    {
        return $this->status === 'frozen';
    }

    /**
     * Check if the wallet has sufficient balance.
     *
     * @param int $amount
     *
     * @return bool
     */
    public function hasSufficientBalance(int $amount): bool
    {
        return $this->balance >= $amount;
    }

    /**
     * Freeze the wallet.
     *
     * @return void
     */
    public function freeze(): void
    {
        $this->status = 'frozen';
        $this->save();
    }

    /**
     * Activate the wallet.
     *
     * @return void
     */
    public function activate(): void
    {
        $this->status = 'active';
        $this->save();
    }
}
