<?php

namespace Fleetbase\Ledger\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\SoftDeletes;

class GlAssignmentRule extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use SoftDeletes;

    protected $table = 'gl_assignment_rules';
    protected $publicIdType = 'gl_rule';

    protected $fillable = [
        'company_uuid', 'name', 'priority', 'match_type',
        'gl_account_uuid', 'target', 'is_active', 'meta',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'priority'  => 'integer',
        'meta'      => Json::class,
    ];

    public function company()
    {
        return $this->belongsTo(\Fleetbase\Models\Company::class, 'company_uuid', 'uuid');
    }

    public function glAccount()
    {
        return $this->belongsTo(Account::class, 'gl_account_uuid', 'uuid');
    }

    public function conditions()
    {
        return $this->hasMany(GlAssignmentCondition::class, 'gl_assignment_rule_uuid', 'uuid');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('priority', 'asc');
    }

    public function matches(array $context): bool
    {
        $conditions = $this->conditions;

        if ($conditions->isEmpty()) {
            return false;
        }

        $results = $conditions->map(function ($condition) use ($context) {
            return $condition->evaluate($context);
        });

        return $this->match_type === 'all'
            ? $results->every(fn ($r) => $r === true)
            : $results->contains(true);
    }
}
