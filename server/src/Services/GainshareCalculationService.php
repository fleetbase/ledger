<?php

namespace Fleetbase\Ledger\Services;

use Fleetbase\FleetOps\Models\Shipment;
use Fleetbase\Ledger\Models\CarrierInvoice;
use Fleetbase\Ledger\Models\CostBenchmark;
use Fleetbase\Ledger\Models\GainshareExecution;
use Fleetbase\Ledger\Models\GainshareRule;
use Fleetbase\Ledger\Models\ServiceAgreement;

/**
 * Calculates gainshare savings by comparing benchmark cost vs actual carrier cost.
 *
 * This service is a financial analytics layer. It:
 * - Finds the applicable benchmark rate for a shipment's lane
 * - Compares benchmark vs approved carrier invoice amount
 * - Applies gainshare split percentages
 * - Stores the result as a GainshareExecution record
 *
 * It does NOT:
 * - Modify shipment state or execution
 * - Modify invoice amounts
 * - Modify routing or tendering
 * - Trigger payments
 * - Send notifications
 */
class GainshareCalculationService
{
    /**
     * Calculate gainshare for a shipment after carrier invoice approval.
     *
     * @param Shipment       $shipment The delivered shipment
     * @param CarrierInvoice $invoice  The approved carrier invoice
     * @return GainshareExecution|null The calculation result, or null if no gainshare applies
     */
    public function calculateForShipment(Shipment $shipment, CarrierInvoice $invoice): ?GainshareExecution
    {
        // Find the customer from the first linked order
        $customerUuid = $shipment->orders()->first()?->customer_uuid;
        if (!$customerUuid) {
            return null;
        }

        // Find active service agreement for this customer
        $agreement = ServiceAgreement::where('company_uuid', $shipment->company_uuid)
            ->where('customer_uuid', $customerUuid)
            ->active()
            ->effective()
            ->first();

        if (!$agreement) {
            return null;
        }

        // Find active gainshare rule for this agreement
        $rule = GainshareRule::where('service_agreement_uuid', $agreement->uuid)
            ->active()
            ->first();

        if (!$rule || $rule->calculation_basis !== 'per_shipment') {
            return null;
        }

        // Find benchmark rate for this lane
        $origin = $shipment->stops()->where('type', 'pickup')->first();
        $dest = $shipment->stops()->where('type', 'delivery')->orderBy('sequence', 'desc')->first();

        $benchmark = CostBenchmark::where('company_uuid', $shipment->company_uuid)
            ->where(function ($q) use ($agreement) {
                $q->where('service_agreement_uuid', $agreement->uuid)
                  ->orWhereNull('service_agreement_uuid');
            })
            ->forLane(
                $origin?->postal_code ?? $origin?->state,
                $dest?->postal_code ?? $dest?->state
            )
            ->where(function ($q) use ($shipment) {
                $q->where('mode', $shipment->mode)->orWhereNull('mode');
            })
            ->active()
            ->effective()
            ->first();

        if (!$benchmark) {
            return null;
        }

        // Calculate actual cost from approved carrier invoice
        $actualCost = $invoice->approved_amount;
        if (!$actualCost) {
            return null;
        }

        // Calculate savings
        $savings = $benchmark->benchmark_rate - $actualCost;

        // Check minimum threshold
        if ($rule->minimum_savings_threshold && $savings < $rule->minimum_savings_threshold) {
            return null;
        }

        // No savings = no gainshare
        if ($savings <= 0) {
            return null;
        }

        // Calculate shares
        $companyShare = round($savings * ($rule->split_percentage_company / 100), 2);
        $clientShare = round($savings * ($rule->split_percentage_client / 100), 2);

        // Find associated client invoice if one exists
        $clientInvoiceUuid = \Fleetbase\Ledger\Models\ClientInvoice::where('shipment_uuid', $shipment->uuid)
            ->value('uuid');

        return GainshareExecution::create([
            'company_uuid'          => $shipment->company_uuid,
            'gainshare_rule_uuid'   => $rule->uuid,
            'shipment_uuid'         => $shipment->uuid,
            'carrier_invoice_uuid'  => $invoice->uuid,
            'client_invoice_uuid'   => $clientInvoiceUuid,
            'cost_benchmark_uuid'   => $benchmark->uuid,
            'benchmark_total'       => $benchmark->benchmark_rate,
            'actual_total'          => $actualCost,
            'savings'               => $savings,
            'company_share'         => $companyShare,
            'client_share'          => $clientShare,
            'status'                => 'calculated',
        ]);
    }

    /**
     * Get aggregate gainshare summary for a customer over a period.
     */
    public function getCustomerSummary(string $companyUuid, string $customerUuid, int $days = 90): array
    {
        $since = now()->subDays($days);

        $executions = GainshareExecution::where('company_uuid', $companyUuid)
            ->whereHas('gainshareRule.serviceAgreement', function ($q) use ($customerUuid) {
                $q->where('customer_uuid', $customerUuid);
            })
            ->where('created_at', '>=', $since)
            ->get();

        return [
            'customer_uuid'      => $customerUuid,
            'period_days'        => $days,
            'total_shipments'    => $executions->count(),
            'total_benchmark'    => round($executions->sum('benchmark_total'), 2),
            'total_actual'       => round($executions->sum('actual_total'), 2),
            'total_savings'      => round($executions->sum('savings'), 2),
            'total_company_share'=> round($executions->sum('company_share'), 2),
            'total_client_share' => round($executions->sum('client_share'), 2),
            'avg_savings_pct'    => $executions->avg(function ($e) {
                return $e->benchmark_total > 0 ? ($e->savings / $e->benchmark_total * 100) : 0;
            }),
        ];
    }
}
