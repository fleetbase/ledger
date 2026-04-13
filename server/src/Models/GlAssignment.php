<?php

namespace Fleetbase\Ledger\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Casts\PolymorphicType;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasUuid;

class GlAssignment extends Model
{
    use HasUuid;
    use HasApiModelBehavior;

    protected $table = 'gl_assignments';

    protected $fillable = [
        'company_uuid', 'gl_account_uuid', 'gl_assignment_rule_uuid',
        'assignable_type', 'assignable_uuid', 'amount', 'assignment_type', 'meta',
    ];

    protected $casts = [
        'amount'          => 'decimal:2',
        'assignable_type' => PolymorphicType::class,
        'meta'            => Json::class,
    ];

    public function company()
    {
        return $this->belongsTo(\Fleetbase\Models\Company::class, 'company_uuid', 'uuid');
    }

    public function glAccount()
    {
        return $this->belongsTo(Account::class, 'gl_account_uuid', 'uuid');
    }

    public function rule()
    {
        return $this->belongsTo(GlAssignmentRule::class, 'gl_assignment_rule_uuid', 'uuid');
    }

    public function assignable()
    {
        return $this->morphTo(__FUNCTION__, 'assignable_type', 'assignable_uuid');
    }
}
