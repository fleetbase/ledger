<?php

namespace Fleetbase\Ledger\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChargeTemplateItem extends Model
{
    use HasUuid, HasApiModelBehavior, SoftDeletes;

    protected $table = 'charge_template_items';

    protected $fillable = [
        'charge_template_uuid', 'charge_type', 'description',
        'calculation_method', 'rate', 'minimum', 'maximum',
        'sequence', 'is_active', 'meta',
    ];

    protected $casts = [
        'rate'      => 'decimal:4',
        'minimum'   => 'decimal:2',
        'maximum'   => 'decimal:2',
        'sequence'  => 'integer',
        'is_active' => 'boolean',
        'meta'      => Json::class,
    ];

    public function template()
    {
        return $this->belongsTo(ChargeTemplate::class, 'charge_template_uuid', 'uuid');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
