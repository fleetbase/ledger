<?php

namespace Fleetbase\Ledger\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasComments;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class PayFile extends Model
{
    use HasUuid, HasPublicId, HasApiModelBehavior, HasComments, SoftDeletes;

    /**
     * Status constants — define the payment lifecycle.
     * draft     → being assembled
     * generated → file content created and stored
     * sent      → file transmitted to AP / bank
     * confirmed → payment confirmed; ONLY THEN are invoices marked paid
     * cancelled → pay file voided; invoices remain available for re-batching
     */
    public const STATUS_DRAFT     = 'draft';
    public const STATUS_GENERATED = 'generated';
    public const STATUS_SENT      = 'sent';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CANCELLED = 'cancelled';

    public const FORMAT_CSV       = 'csv';
    public const FORMAT_EDI_820   = 'edi_820';
    public const FORMAT_ACH_NACHA = 'ach_nacha';

    protected $table = 'pay_files';
    protected $publicIdType = 'payfile';

    protected $fillable = [
        'company_uuid', 'name', 'format', 'status',
        'period_start', 'period_end', 'file_uuid',
        'record_count', 'total_amount',
        'generated_at', 'sent_at', 'confirmed_at',
        'meta',
    ];

    protected $casts = [
        'period_start'  => 'date',
        'period_end'    => 'date',
        'generated_at'  => 'datetime',
        'sent_at'       => 'datetime',
        'confirmed_at'  => 'datetime',
        'total_amount'  => 'decimal:2',
        'record_count'  => 'integer',
        'meta'          => Json::class,
    ];

    public function company()
    {
        return $this->belongsTo(\Fleetbase\Models\Company::class, 'company_uuid', 'uuid');
    }

    public function items()
    {
        return $this->hasMany(PayFileItem::class, 'pay_file_uuid', 'uuid');
    }

    public function file()
    {
        return $this->belongsTo(\Fleetbase\Models\File::class, 'file_uuid', 'uuid');
    }

    /**
     * Scope: pay files that "lock" invoices (block re-inclusion in another pay file).
     * Anything NOT cancelled holds the invoices.
     */
    public function scopeLocking($query)
    {
        return $query->where('status', '!=', self::STATUS_CANCELLED);
    }

    /**
     * Mark this pay file as transmitted. Does NOT mark invoices paid yet.
     */
    public function markAsSent(): self
    {
        if ($this->status !== self::STATUS_GENERATED) {
            throw new \RuntimeException("PayFile must be in 'generated' state to mark as sent. Current: {$this->status}");
        }

        $this->update([
            'status'  => self::STATUS_SENT,
            'sent_at' => now(),
        ]);

        return $this;
    }

    /**
     * Mark this pay file as confirmed (payment actually completed).
     * THIS is the ONLY place that flips CarrierInvoice.status to 'paid'.
     *
     * Wrapped in a DB transaction so either ALL invoices flip or none do.
     */
    public function markAsConfirmed(): self
    {
        if (!in_array($this->status, [self::STATUS_SENT, self::STATUS_GENERATED])) {
            throw new \RuntimeException("PayFile must be in 'sent' or 'generated' state to confirm. Current: {$this->status}");
        }

        DB::transaction(function () {
            $invoiceUuids = $this->items()->pluck('carrier_invoice_uuid')->all();

            if (!empty($invoiceUuids)) {
                CarrierInvoice::whereIn('uuid', $invoiceUuids)
                    ->where('status', 'approved') // safety: only flip approved → paid
                    ->update(['status' => 'paid']);
            }

            $this->update([
                'status'       => self::STATUS_CONFIRMED,
                'confirmed_at' => now(),
            ]);
        });

        return $this->fresh();
    }

    /**
     * Cancel a pay file. Releases invoices for re-batching.
     * Does NOT touch invoice paid state — invoices in a cancelled file
     * stay as 'approved' and become eligible for the next pay file.
     */
    public function cancel(): self
    {
        if ($this->status === self::STATUS_CONFIRMED) {
            throw new \RuntimeException('Cannot cancel a confirmed pay file — payment has already been recorded.');
        }

        $this->update(['status' => self::STATUS_CANCELLED]);

        return $this;
    }
}
