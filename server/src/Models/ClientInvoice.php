<?php

namespace Fleetbase\Ledger\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasComments;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Fleetbase\Ledger\Traits\HasGlAssignments;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientInvoice extends Model
{
    use HasUuid, HasPublicId, HasApiModelBehavior, HasComments, HasGlAssignments, Searchable, SoftDeletes;

    protected $table = 'client_invoices';
    protected $publicIdType = 'inv';
    protected $searchableColumns = ['invoice_number'];

    protected $fillable = [
        'company_uuid', 'customer_uuid', 'service_agreement_uuid',
        'shipment_uuid', 'invoice_number', 'status',
        'subtotal', 'tax_amount', 'total_amount',
        'invoice_date', 'due_date', 'period_start', 'period_end',
        'sent_at', 'paid_at', 'currency', 'notes', 'meta',
    ];

    protected $casts = [
        'subtotal'     => 'decimal:2',
        'tax_amount'   => 'decimal:2',
        'total_amount' => 'decimal:2',
        'invoice_date' => 'date',
        'due_date'     => 'date',
        'period_start' => 'date',
        'period_end'   => 'date',
        'sent_at'      => 'datetime',
        'paid_at'      => 'datetime',
        'meta'         => Json::class,
    ];

    public function company()
    {
        return $this->belongsTo(\Fleetbase\Models\Company::class, 'company_uuid', 'uuid');
    }

    public function serviceAgreement()
    {
        return $this->belongsTo(ServiceAgreement::class, 'service_agreement_uuid', 'uuid');
    }

    public function items()
    {
        return $this->hasMany(ClientInvoiceItem::class, 'client_invoice_uuid', 'uuid');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'sent')
                     ->where('due_date', '<', now());
    }
}
