<?php

namespace Fleetbase\Ledger\Services;

use Fleetbase\Ledger\Models\Account;
use Fleetbase\Ledger\Models\GlAssignment;
use Fleetbase\Ledger\Models\GlAssignmentRule;
use Illuminate\Database\Eloquent\Model;

class GlAutoAssignmentService
{
    /**
     * Auto-assign a GL code to a record.
     */
    public function assignForRecord(Model $record, string $target, float $amount): ?GlAssignment
    {
        $companyUuid = $record->company_uuid;

        $context = $this->buildContext($record);

        $rules = GlAssignmentRule::where('company_uuid', $companyUuid)
            ->where('target', $target)
            ->active()
            ->ordered()
            ->with('conditions')
            ->get();

        foreach ($rules as $rule) {
            if ($rule->matches($context)) {
                return GlAssignment::create([
                    'company_uuid'            => $companyUuid,
                    'gl_account_uuid'         => $rule->gl_account_uuid,
                    'gl_assignment_rule_uuid'  => $rule->uuid,
                    'assignable_type'          => get_class($record),
                    'assignable_uuid'          => $record->uuid,
                    'amount'                   => $amount,
                    'assignment_type'          => 'auto',
                ]);
            }
        }

        // Fallback to default GL account
        $defaultGl = Account::where('company_uuid', $companyUuid)
            ->where('meta->is_default', true)
            ->active()
            ->first();

        if ($defaultGl) {
            return GlAssignment::create([
                'company_uuid'            => $companyUuid,
                'gl_account_uuid'         => $defaultGl->uuid,
                'gl_assignment_rule_uuid'  => null,
                'assignable_type'          => get_class($record),
                'assignable_uuid'          => $record->uuid,
                'amount'                   => $amount,
                'assignment_type'          => 'auto',
                'meta'                     => ['note' => 'Assigned via default GL — no rule matched'],
            ]);
        }

        return null;
    }

    protected function buildContext(Model $record): array
    {
        $context = [];

        // If model implements getGlContext(), use it
        if (method_exists($record, 'getGlContext')) {
            $context = array_merge($context, $record->getGlContext());
        }

        // Handle Order (by class check to avoid hard dependency)
        if ($record instanceof \Fleetbase\FleetOps\Models\Order) {
            $context['customer']       = $record->customer_uuid ?? null;
            $context['mode']           = $record->meta['mode'] ?? null;
            $context['equipment_type'] = $record->meta['equipment_type'] ?? null;
            $context['carrier']        = $record->facilitator_uuid ?? null;
            $context['department']     = $record->meta['department'] ?? null;
            $context['cost_center']    = $record->meta['cost_center'] ?? null;

            $pickup  = $record->payload?->pickup;
            $dropoff = $record->payload?->dropoff;
            if ($pickup) {
                $context['origin_state'] = $pickup->state ?? null;
                $context['origin_zip']   = $pickup->postal_code ?? null;
            }
            if ($dropoff) {
                $context['dest_state'] = $dropoff->state ?? null;
                $context['dest_zip']   = $dropoff->postal_code ?? null;
            }
        }

        return $context;
    }
}
