<?php

namespace Fleetbase\Ledger\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Ledger\Models\Transaction;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Journal extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use HasMetaAttributes;
    use TracksApiCredential;
    use SoftDeletes;

    /**
     * The prefix used when auto-generating public IDs for journal entries.
     *
     * @var string
     */
    public $publicIdPrefix = 'journal';

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'ledger_journals';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        '_key',
        'public_id',
        'company_uuid',
        'transaction_uuid',
        'debit_account_uuid',
        'credit_account_uuid',
        'amount',
        'currency',
        'description',
        'date',
        'meta',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'amount' => 'integer',
        'date'   => 'date',
        'meta'   => Json::class,
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = ['public_id'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [];

    /**
     * The transaction this journal entry belongs to.
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_uuid');
    }

    /**
     * The account being debited.
     */
    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'debit_account_uuid');
    }

    /**
     * The account being credited.
     */
    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'credit_account_uuid');
    }
}
