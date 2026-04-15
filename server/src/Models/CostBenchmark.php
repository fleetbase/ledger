<?php

namespace Fleetbase\Ledger\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Illuminate\Database\Eloquent\SoftDeletes;

class CostBenchmark extends Model
{
    use HasUuid, HasPublicId, HasApiModelBehavior, Searchable, SoftDeletes;

    protected $table = 'cost_benchmarks';
    protected $publicIdType = 'benchmark';
    protected $searchableColumns = ['lane_origin', 'lane_destination'];

    protected $fillable = [
        'company_uuid', 'service_agreement_uuid', 'benchmark_type',
        'lane_origin', 'lane_destination', 'mode', 'equipment_type',
        'benchmark_rate', 'rate_unit', 'effective_date', 'expiration_date',
        'is_active', 'meta',
    ];

    protected $casts = [
        'benchmark_rate'  => 'decimal:2',
        'is_active'       => 'boolean',
        'effective_date'  => 'date',
        'expiration_date' => 'date',
        'meta'            => Json::class,
    ];

    public function company()
    {
        return $this->belongsTo(\Fleetbase\Models\Company::class, 'company_uuid', 'uuid');
    }

    public function serviceAgreement()
    {
        return $this->belongsTo(ServiceAgreement::class, 'service_agreement_uuid', 'uuid');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeEffective($query)
    {
        return $query->where('effective_date', '<=', now())
            ->where(function ($q) {
                $q->whereNull('expiration_date')->orWhere('expiration_date', '>=', now());
            });
    }

    public function scopeForLane($query, ?string $origin, ?string $destination)
    {
        if ($origin) $query->where('lane_origin', $origin);
        if ($destination) $query->where('lane_destination', $destination);
        return $query;
    }
}
