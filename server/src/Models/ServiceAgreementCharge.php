<?php

namespace Fleetbase\Ledger\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceAgreementCharge extends Model
{
    use HasUuid, HasApiModelBehavior, SoftDeletes;

    protected $table = 'service_agreement_charges';

    protected $fillable = [
        'service_agreement_uuid', 'charge_template_uuid',
        'overrides', 'is_active', 'meta',
    ];

    protected $casts = [
        'overrides' => Json::class,
        'is_active' => 'boolean',
        'meta'      => Json::class,
    ];

    public function serviceAgreement()
    {
        return $this->belongsTo(ServiceAgreement::class, 'service_agreement_uuid', 'uuid');
    }

    public function chargeTemplate()
    {
        return $this->belongsTo(ChargeTemplate::class, 'charge_template_uuid', 'uuid');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
