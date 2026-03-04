<?php

namespace Fleetbase\Ledger\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Casts\Money;
use Fleetbase\Casts\PolymorphicType;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\Model;
use Fleetbase\Models\Template;
use Fleetbase\Models\Transaction;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Fleetbase\Traits\SendsWebhooks;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use HasMetaAttributes;
    use TracksApiCredential;
    use Searchable;
    use SendsWebhooks;
    use SoftDeletes;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'ledger_invoices';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'invoice';

    /**
     * The response payload key to use.
     */
    protected $payloadKey = 'invoice';

    /**
     * The attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['number', 'public_id'];

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
        'customer_uuid',
        'customer_type',
        'order_uuid',
        'transaction_uuid',
        'template_uuid',
        'number',
        'date',
        'due_date',
        'subtotal',
        'tax',
        'total_amount',
        'amount_paid',
        'balance',
        'currency',
        'status',
        'notes',
        'terms',
        'meta',
        'sent_at',
        'viewed_at',
        'paid_at',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'date'          => 'date',
        'due_date'      => 'date',
        'subtotal'      => Money::class,
        'tax'           => Money::class,
        'total_amount'  => Money::class,
        'amount_paid'   => Money::class,
        'balance'       => Money::class,
        'customer_type' => PolymorphicType::class,
        'meta'          => Json::class,
        'sent_at'       => 'datetime',
        'viewed_at'     => 'datetime',
        'paid_at'       => 'datetime',
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
     * The customer for this invoice.
     */
    public function customer(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'customer_type', 'customer_uuid')->withoutGlobalScopes();
    }

    /**
     * The order this invoice is for.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_uuid');
    }

    /**
     * The transaction associated with this invoice.
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_uuid');
    }

    /**
     * The template used to render this invoice.
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'template_uuid');
    }

    /**
     * The line items for this invoice.
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class, 'invoice_uuid');
    }

    /**
     * Generate a unique invoice number.
     */
    public static function generateNumber(string $prefix = 'INV', int $length = 6): string
    {
        $number = $prefix . '-' . str_pad(mt_rand(1, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
        $exists = self::where('number', $number)->withTrashed()->exists();

        while ($exists) {
            $number = $prefix . '-' . str_pad(mt_rand(1, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
            $exists = self::where('number', $number)->withTrashed()->exists();
        }

        return $number;
    }

    /**
     * Calculate totals from line items.
     */
    public function calculateTotals(): void
    {
        $this->subtotal     = $this->items()->sum('amount');
        $this->tax          = $this->items()->sum('tax_amount');
        $this->total_amount = $this->subtotal + $this->tax;
        $this->balance      = $this->total_amount - $this->amount_paid;
    }

    /**
     * Mark the invoice as sent.
     */
    public function markAsSent(): void
    {
        $this->status  = 'sent';
        $this->sent_at = now();
        $this->save();
    }

    /**
     * Mark the invoice as viewed.
     */
    public function markAsViewed(): void
    {
        if (!$this->viewed_at) {
            $this->viewed_at = now();
            $this->save();
        }
    }

    /**
     * Mark the invoice as paid.
     */
    public function markAsPaid(): void
    {
        $this->status      = 'paid';
        $this->amount_paid = $this->total_amount;
        $this->balance     = 0;
        $this->paid_at     = now();
        $this->save();
    }

    /**
     * Check if the invoice is overdue.
     */
    public function isOverdue(): bool
    {
        return $this->due_date && $this->due_date->isPast() && $this->status !== 'paid';
    }

    /**
     * Check if the invoice is paid.
     */
    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}
