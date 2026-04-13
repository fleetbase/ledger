<?php

namespace Fleetbase\Ledger\Services;

use Fleetbase\Ledger\Models\CarrierInvoice;
use Fleetbase\Ledger\Models\CarrierInvoiceAuditRule;

class CarrierInvoiceAuditService
{
    public function audit(CarrierInvoice $invoice): CarrierInvoice
    {
        $invoice->load('items');

        if ($invoice->planned_amount !== null) {
            $discrepancy = $invoice->invoiced_amount - $invoice->planned_amount;
            $invoice->discrepancy_amount = $discrepancy;
            $invoice->discrepancy_percent = $invoice->planned_amount > 0
                ? round(abs($discrepancy) / $invoice->planned_amount * 100, 2)
                : null;
            $invoice->discrepancy_type = match (true) {
                $discrepancy > 0.01  => 'overcharge',
                $discrepancy < -0.01 => 'undercharge',
                default              => 'none',
            };
        }

        foreach ($invoice->items as $item) {
            if ($item->planned_amount !== null) {
                $item->discrepancy_amount = $item->invoiced_amount - $item->planned_amount;
                $item->save();
            }
        }

        $rules = CarrierInvoiceAuditRule::where('company_uuid', $invoice->company_uuid)
            ->active()
            ->ordered()
            ->get();

        $autoApprove = $this->evaluateRules($invoice, $rules);

        if ($autoApprove) {
            $invoice->status = 'audited';
            $invoice->approved_amount = $invoice->invoiced_amount;
        } else {
            $invoice->status = 'in_review';
        }

        $invoice->save();

        return $invoice;
    }

    protected function evaluateRules(CarrierInvoice $invoice, $rules): bool
    {
        if ($invoice->discrepancy_type === 'none') {
            return true;
        }

        foreach ($rules as $rule) {
            if ($rule->charge_type && $rule->charge_type !== 'all') {
                continue;
            }

            if ($rule->rule_type === 'tolerance') {
                $withinPercent = $rule->tolerance_percent === null
                    || ($invoice->discrepancy_percent !== null && $invoice->discrepancy_percent <= $rule->tolerance_percent);

                $withinAmount = $rule->tolerance_amount === null
                    || (abs($invoice->discrepancy_amount) <= $rule->tolerance_amount);

                if ($withinPercent && $withinAmount) {
                    return true;
                }
            }

            if ($rule->rule_type === 'auto_approve' && $invoice->invoiced_amount <= ($rule->tolerance_amount ?? PHP_FLOAT_MAX)) {
                return true;
            }
        }

        return false;
    }

    public function resolve(CarrierInvoice $invoice, string $resolution, ?float $customAmount = null, ?string $notes = null): CarrierInvoice
    {
        $invoice->resolution = $resolution;
        $invoice->resolution_notes = $notes;
        $invoice->resolved_by = session('user');
        $invoice->resolved_at = now();

        $invoice->approved_amount = match ($resolution) {
            'pay_invoiced' => $invoice->invoiced_amount,
            'pay_planned'  => $invoice->planned_amount,
            'pay_custom'   => $customAmount,
            'disputed'     => null,
        };

        $invoice->status = $resolution === 'disputed' ? 'disputed' : 'approved';
        $invoice->save();

        if ($invoice->status === 'approved') {
            event(new \Fleetbase\Ledger\Events\CarrierInvoiceApproved($invoice));
        }

        return $invoice;
    }
}
