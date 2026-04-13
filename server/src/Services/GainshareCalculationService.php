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
 * - Converts benchmark to a flat expected_total based on rate_unit (flat, per_mile, per_cwt)
 * - Compares expected_total vs actual_total (approved carrier invoice amount)
 * - Classifies the result: savings, loss, break_even, below_threshold
 * - Applies gainshare split percentages (only for savings above threshold)
 * - Stores all results as GainshareExecution records (including losses)
 * - Prevents duplicate executions for the same shipment+rule combination
 *
 * It does NOT:
 * - Modify shipment state or execution
 * - Modify invoice amounts
 * - Modify routing or tendering
 * - Trigger payments or send notifications
 */
class GainshareCalculationService
{
    /**
     * Calculate gainshare for a shipment after carrier invoice approval.
     *
     * Returns null ONLY when required inputs are missing (no customer, no agreement,
     * no rule, no benchmark, missing unit data). All calculable outcomes — including
     * losses and break-even — produce a GainshareExecution record.
     *
     * @param Shipment       $shipment The delivered shipment
     * @param CarrierInvoice $invoice  The approved carrier invoice
     * @return GainshareExecution|null The calculation result, or null if inputs are missing
     */
    public function calculateForShipment(Shipment $shipment, CarrierInvoice $invoice): ?GainshareExecution
    {
        // --- Resolve required inputs ---

        $customerUuid = $shipment->orders()->first()?->customer_uuid;
        if (!$customerUuid) {
            return null; // No customer on linked orders — cannot determine agreement
        }

        $agreement = ServiceAgreement::where('company_uuid', $shipment->company_uuid)
            ->where('customer_uuid', $customerUuid)
            ->active()
            ->effective()
            ->first();

        if (!$agreement) {
            return null; // No active agreement — gainshare not applicable
        }

        $rule = GainshareRule::where('service_agreement_uuid', $agreement->uuid)
            ->active()
            ->first();

        if (!$rule || $rule->calculation_basis !== 'per_shipment') {
            return null; // No rule or not per-shipment basis
        }

        // --- Deduplication guard ---
        // Prevent duplicate executions for the same shipment+rule combination.
        // If one already exists, update it rather than creating a duplicate.
        $existingExecution = GainshareExecution::where('shipment_uuid', $shipment->uuid)
            ->where('gainshare_rule_uuid', $rule->uuid)
            ->first();

        // --- Find benchmark ---

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
            return null; // No benchmark for this lane/mode — cannot calculate
        }

        // --- Derive actual cost ---

        $actualTotal = (float) $invoice->approved_amount;
        if ($actualTotal <= 0) {
            return null; // No approved amount — cannot calculate
        }

        // --- Convert benchmark to flat expected_total based on rate_unit ---

        $expectedTotal = $this->convertBenchmarkToFlat($benchmark, $shipment);

        if ($expectedTotal === null) {
            // Missing required data (miles or weight) for unit conversion.
            // Do NOT fabricate numbers — skip safely.
            return null;
        }

        // --- Calculate savings and classify result ---

        $savings = round($expectedTotal - $actualTotal, 2);

        $resultType = $this->classifyResult($savings, $rule);

        // Calculate shares only for qualifying savings
        $companyShare = 0;
        $clientShare = 0;

        if ($resultType === GainshareExecution::RESULT_SAVINGS) {
            $companyShare = round($savings * ($rule->split_percentage_company / 100), 2);
            $clientShare = round($savings * ($rule->split_percentage_client / 100), 2);
        }

        // Find associated client invoice
        $clientInvoiceUuid = \Fleetbase\Ledger\Models\ClientInvoice::where('shipment_uuid', $shipment->uuid)
            ->value('uuid');

        // --- Create or update execution ---

        $executionData = [
            'company_uuid'          => $shipment->company_uuid,
            'gainshare_rule_uuid'   => $rule->uuid,
            'shipment_uuid'         => $shipment->uuid,
            'carrier_invoice_uuid'  => $invoice->uuid,
            'client_invoice_uuid'   => $clientInvoiceUuid,
            'cost_benchmark_uuid'   => $benchmark->uuid,
            'benchmark_total'       => round($expectedTotal, 2),
            'actual_total'          => round($actualTotal, 2),
            'savings'               => $savings,
            'company_share'         => $companyShare,
            'client_share'          => $clientShare,
            'result_type'           => $resultType,
            'status'                => 'calculated',
        ];

        if ($existingExecution) {
            // Update existing execution rather than creating duplicate
            $existingExecution->update($executionData);
            return $existingExecution;
        }

        return GainshareExecution::create($executionData);
    }

    /**
     * Convert a benchmark rate to a flat dollar total based on its rate_unit.
     *
     * Returns null if required shipment data is missing for the conversion,
     * preventing misleading calculations.
     *
     * @param CostBenchmark $benchmark The benchmark with rate and unit
     * @param Shipment      $shipment  The shipment providing miles/weight context
     * @return float|null The flat expected total, or null if conversion is impossible
     */
    protected function convertBenchmarkToFlat(CostBenchmark $benchmark, Shipment $shipment): ?float
    {
        $rate = (float) $benchmark->benchmark_rate;

        return match ($benchmark->rate_unit) {
            'flat' => $rate,

            'per_mile' => $this->convertPerMile($rate, $shipment),

            'per_cwt' => $this->convertPerCwt($rate, $shipment),

            // Unknown rate unit — cannot safely convert
            default => null,
        };
    }

    /**
     * Convert per-mile rate to flat total.
     * Returns null if shipment miles are unavailable.
     */
    protected function convertPerMile(float $rate, Shipment $shipment): ?float
    {
        $miles = (float) ($shipment->carrier_rate_miles ?? 0);

        if ($miles <= 0) {
            // No mileage data on shipment — cannot convert per_mile benchmark.
            // Returning null prevents fabricating a misleading calculation.
            return null;
        }

        return round($rate * $miles, 2);
    }

    /**
     * Convert per-CWT rate to flat total.
     * Returns null if shipment weight is unavailable.
     */
    protected function convertPerCwt(float $rate, Shipment $shipment): ?float
    {
        $weight = (float) ($shipment->total_weight ?? 0);

        if ($weight <= 0) {
            // No weight data on shipment — cannot convert per_cwt benchmark.
            return null;
        }

        return round($rate * ($weight / 100), 2);
    }

    /**
     * Classify the gainshare result based on savings amount and threshold.
     */
    protected function classifyResult(float $savings, GainshareRule $rule): string
    {
        if ($savings < 0) {
            return GainshareExecution::RESULT_LOSS;
        }

        // Use abs() < 0.01 for float comparison to handle rounding
        if (abs($savings) < 0.01) {
            return GainshareExecution::RESULT_BREAK_EVEN;
        }

        if ($rule->minimum_savings_threshold && $savings < $rule->minimum_savings_threshold) {
            return GainshareExecution::RESULT_BELOW_THRESHOLD;
        }

        return GainshareExecution::RESULT_SAVINGS;
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

        $savingsExecs = $executions->where('result_type', GainshareExecution::RESULT_SAVINGS);
        $lossExecs = $executions->where('result_type', GainshareExecution::RESULT_LOSS);

        return [
            'customer_uuid'       => $customerUuid,
            'period_days'         => $days,
            'total_executions'    => $executions->count(),
            'savings_count'       => $savingsExecs->count(),
            'loss_count'          => $lossExecs->count(),
            'break_even_count'    => $executions->where('result_type', GainshareExecution::RESULT_BREAK_EVEN)->count(),
            'below_threshold_count' => $executions->where('result_type', GainshareExecution::RESULT_BELOW_THRESHOLD)->count(),
            'total_benchmark'     => round($executions->sum('benchmark_total'), 2),
            'total_actual'        => round($executions->sum('actual_total'), 2),
            'net_savings'         => round($executions->sum('savings'), 2), // includes negative (losses)
            'total_savings_only'  => round($savingsExecs->sum('savings'), 2),
            'total_losses_only'   => round(abs($lossExecs->sum('savings')), 2),
            'total_company_share' => round($executions->sum('company_share'), 2),
            'total_client_share'  => round($executions->sum('client_share'), 2),
            'avg_savings_pct'     => $executions->count() > 0
                ? round($executions->avg(function ($e) {
                    return $e->benchmark_total > 0 ? ($e->savings / $e->benchmark_total * 100) : 0;
                }), 2)
                : null,
        ];
    }
}
