<?php

namespace Fleetbase\Ledger\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\SoftDeletes;

class CarrierInvoiceAuditRule extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use SoftDeletes;

    protected $table = 'carrier_invoice_audit_rules';
    protected $publicIdType = 'audit_rule';

    protected $fillable = [
        'company_uuid', 'name', 'rule_type',
        'tolerance_percent', 'tolerance_amount',
        'charge_type', 'is_active', 'priority', 'meta',
    ];

    protected $casts = [
        'tolerance_percent' => 'decimal:2',
        'tolerance_amount'  => 'decimal:2',
        'is_active'         => 'boolean',
        'priority'          => 'integer',
        'meta'              => Json::class,
    ];

    public function company()
    {
        return $this->belongsTo(\Fleetbase\Models\Company::class, 'company_uuid', 'uuid');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('priority', 'asc');
    }
}
