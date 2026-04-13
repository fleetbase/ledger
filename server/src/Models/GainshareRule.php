<?php

namespace Fleetbase\Ledger\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\SoftDeletes;

class GainshareRule extends Model
{
    use HasUuid, HasPublicId, HasApiModelBehavior, SoftDeletes;

    protected $table = 'gainshare_rules';
    protected $publicIdType = 'gs_rule';

    protected $fillable = [
        'company_uuid', 'service_agreement_uuid', 'calculation_basis',
        'split_percentage_company', 'split_percentage_client',
        'minimum_savings_threshold', 'is_active', 'meta',
    ];

    protected $casts = [
        'split_percentage_company'  => 'decimal:2',
        'split_percentage_client'   => 'decimal:2',
        'minimum_savings_threshold' => 'decimal:2',
        'is_active'                 => 'boolean',
        'meta'                      => Json::class,
    ];

    public function company()
    {
        return $this->belongsTo(\Fleetbase\Models\Company::class, 'company_uuid', 'uuid');
    }

    public function serviceAgreement()
    {
        return $this->belongsTo(ServiceAgreement::class, 'service_agreement_uuid', 'uuid');
    }

    public function executions()
    {
        return $this->hasMany(GainshareExecution::class, 'gainshare_rule_uuid', 'uuid');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
