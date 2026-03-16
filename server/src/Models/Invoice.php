<?php

namespace Fleetbase\Ledger\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Casts\Money;
use Fleetbase\Casts\PolymorphicType;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\Model;
use Fleetbase\Models\Setting;
use Fleetbase\Models\Template;
use Fleetbase\Models\Transaction;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Fleetbase\Traits\SendsWebhooks;
use Fleetbase\Traits\TracksApiCredential;
use Carbon\Carbon;
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
     * The model's default attribute values.
     *
     * @var array
     */
    protected $attributes = [
        'status' => 'draft',
    ];

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
     * Relationships that should always be eager-loaded.
     * This ensures customer, items and template are present on every
     * response (index, findRecord, etc.) without needing explicit ->with() calls.
     *
     * @var array
     */
    protected $with = ['customer', 'items', 'template'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [];

    /**
     * Bootstrap the model and its traits.
     *
     * Automatically fills defaults from the company's Invoice Settings when a
     * new invoice is being created, so callers never need to worry about:
     *   - generating a unique invoice number (using the configured prefix)
     *   - picking the right currency
     *   - calculating the due date from the configured payment-terms offset
     *   - pre-filling default notes / terms text
     */
    public static function boot(): void
    {
        parent::boot();

        static::creating(function (Invoice $invoice): void {
            // ── Load company invoice settings ──────────────────────────────────
            // Setting::lookupCompany uses session('company') which is always set
            // for authenticated internal requests. Returns [] when not yet saved.
            $settings = Setting::lookupCompany('ledger.invoice-settings', []);
            if (!is_array($settings)) {
                $settings = [];
            }

            $prefix            = data_get($settings, 'invoice_prefix', 'INV');
            // Use null (not 30) as the fallback so we only apply an offset when
            // the user has explicitly saved one in Invoice Settings. A missing or
            // null value means "no default due date" — we never want to silently
            // pre-fill a date the user never asked for.
            $dueDateOffset     = isset($settings['due_date_offset_days'])
                ? (int) $settings['due_date_offset_days']
                : null;
            $defCurrency       = data_get($settings, 'default_currency');
            $defNotes          = data_get($settings, 'default_notes');
            $defTerms          = data_get($settings, 'default_terms');
            $defTemplateUuid   = data_get($settings, 'default_template_uuid');

            // ── Invoice number ─────────────────────────────────────────────────
            // Always auto-generate when the caller has not explicitly provided one.
            // This is the primary fix for the SQLSTATE[23000] null-number error.
            if (empty($invoice->number)) {
                $invoice->number = static::generateNumber($prefix);
            }

            // ── Currency ───────────────────────────────────────────────────────
            // Apply the settings default only when the caller has not already set
            // a currency on this invoice (e.g. createFromOrder sets it explicitly).
            if (empty($invoice->currency) && !empty($defCurrency)) {
                $invoice->currency = $defCurrency;
            }

            // ── Due date ───────────────────────────────────────────────────────
            // Only derive a due date when the user has explicitly configured a
            // non-zero offset in Invoice Settings. When the setting is absent or
            // zero we leave due_date null so the form stays empty.
            if (empty($invoice->due_date) && $dueDateOffset !== null && $dueDateOffset > 0) {
                $baseDate          = $invoice->date ?? now();
                $invoice->due_date = Carbon::parse($baseDate)->addDays($dueDateOffset);
            }

            // ── Notes / Terms ──────────────────────────────────────────────────
            // Pre-fill with the saved defaults only when the caller left them blank.
            if (empty($invoice->notes) && !empty($defNotes)) {
                $invoice->notes = $defNotes;
            }
            if (empty($invoice->terms) && !empty($defTerms)) {
                $invoice->terms = $defTerms;
            }

            // ── Default template ───────────────────────────────────────────────
            // Apply the company's default invoice template when the caller has not
            // explicitly set one. This ensures auto-generated invoices (e.g. from
            // Fleet-Ops purchase rates) are rendered with the correct branding.
            if (empty($invoice->template_uuid) && !empty($defTemplateUuid)) {
                $invoice->template_uuid = $defTemplateUuid;
            }
        });
    }

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
     *
     * Uses a zero-padded random integer so numbers look like INV-004821.
     * Retries until a number that does not already exist (including soft-deleted
     * records) is found, guaranteeing uniqueness across the entire table.
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
     *
     * Sets viewed_at timestamp on first access and auto-transitions status
     * from 'sent' → 'viewed' so the sender can see the invoice has been opened.
     * Already-viewed, overdue, partial, or paid invoices are not downgraded.
     */
    public function markAsViewed(): void
    {
        if (!$this->viewed_at) {
            $this->viewed_at = now();

            // Only advance the status if the invoice is still in 'sent' state.
            // We never downgrade from overdue/partial/paid back to 'viewed'.
            if ($this->status === 'sent') {
                $this->status = 'viewed';
            }

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
