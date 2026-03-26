<?php

namespace Fleetbase\Ledger\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Casts\Money;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class InvoiceItem extends Model
{
    use HasUuid;
    use SoftDeletes;
    use HasMetaAttributes;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'ledger_invoice_items';

    /**
     * The response payload key to use.
     */
    protected $payloadKey = 'invoice_item';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        '_key',
        'invoice_uuid',
        'description',
        'quantity',
        'unit_price',
        'amount',
        'tax_rate',
        'tax_amount',
        'meta',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'quantity'   => 'integer',
        'unit_price' => Money::class,
        'amount'     => Money::class,
        'tax_rate'   => 'decimal:2',
        'tax_amount' => Money::class,
        'meta'       => Json::class,
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
     * The invoice this item belongs to.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_uuid');
    }

    /**
     * Calculate the line item amount from quantity and unit_price.
     * All monetary values are stored as integer cents.
     */
    public function calculateAmount(): void
    {
        $this->amount     = $this->quantity * $this->unit_price;
        $this->tax_amount = (int) round($this->amount * ($this->tax_rate / 100));
    }
}
