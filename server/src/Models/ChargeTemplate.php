<?php

namespace Fleetbase\Ledger\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChargeTemplate extends Model
{
    use HasUuid, HasPublicId, HasApiModelBehavior, Searchable, SoftDeletes;

    protected $table = 'charge_templates';
    protected $publicIdType = 'chrg_tpl';
    protected $searchableColumns = ['name', 'description'];

    protected $fillable = [
        'company_uuid', 'name', 'description', 'is_active', 'meta',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'meta'      => Json::class,
    ];

    public function company()
    {
        return $this->belongsTo(\Fleetbase\Models\Company::class, 'company_uuid', 'uuid');
    }

    public function items()
    {
        return $this->hasMany(ChargeTemplateItem::class, 'charge_template_uuid', 'uuid')
                    ->orderBy('sequence');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
