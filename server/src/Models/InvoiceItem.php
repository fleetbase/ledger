<?php

namespace Fleetbase\Ledger\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    use HasUuid;
    use HasMetaAttributes;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'ledger_invoice_items';

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
        'unit_price' => 'integer',
        'amount'     => 'integer',
        'tax_rate'   => 'decimal:2',
        'tax_amount' => 'integer',
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
     * Calculate the line item amount.
     *
     * @return void
     */
    public function calculateAmount(): void
    {
        $this->amount     = $this->quantity * $this->unit_price;
        $this->tax_amount = (int) round($this->amount * ($this->tax_rate / 100));
    }
}
