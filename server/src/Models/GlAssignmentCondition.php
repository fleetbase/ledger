<?php

namespace Fleetbase\Ledger\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasUuid;

class GlAssignmentCondition extends Model
{
    use HasUuid;
    use HasApiModelBehavior;

    protected $table = 'gl_assignment_conditions';

    protected $fillable = [
        'gl_assignment_rule_uuid', 'field', 'operator', 'value', 'meta',
    ];

    protected $casts = [
        'meta' => Json::class,
    ];

    public function rule()
    {
        return $this->belongsTo(GlAssignmentRule::class, 'gl_assignment_rule_uuid', 'uuid');
    }

    public function evaluate(array $context): bool
    {
        $fieldValue = $context[$this->field] ?? null;

        if ($fieldValue === null) {
            return false;
        }

        $conditionValue = $this->value;

        if (in_array($this->operator, ['in', 'not_in'])) {
            $conditionValue = json_decode($conditionValue, true) ?: [$conditionValue];
        }

        return match ($this->operator) {
            'equals'     => (string) $fieldValue === (string) $conditionValue,
            'not_equals' => (string) $fieldValue !== (string) $conditionValue,
            'in'         => in_array((string) $fieldValue, $conditionValue),
            'not_in'     => !in_array((string) $fieldValue, $conditionValue),
            'contains'   => str_contains(strtolower($fieldValue), strtolower($conditionValue)),
            default      => false,
        };
    }
}
