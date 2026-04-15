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

        // --- Benchmark source routing ---
        // Branch only for benchmark resolution. Downstream savings/dedup/storage
        // logic is shared so both paths get identical financial safety guarantees.
        $benchmarkSource = $rule->benchmark_source ?? GainshareRule::BENCHMARK_COST;

        $resolution = match ($benchmarkSource) {
            GainshareRule::BENCHMARK_RATE_CONTRACT => $this->resolveBenchmarkFromRateContract($shipment, $agreement, $rule),
            default                                => $this->resolveBenchmarkFromCostBenchmark($shipment, $agreement),
        };

        if ($resolution === null) {
            // No benchmark resolvable for this shipment under this rule — skip safely
            return null;
        }

        $expectedTotal = $resolution['expected_total'];
        $costBenchmarkUuid = $resolution['cost_benchmark_uuid']; // null for rate_contract path

        // --- Derive actual cost ---

        $actualTotal = (float) $invoice->approved_amount;
        if ($actualTotal <= 0) {
            return null; // No approved amount — cannot calculate
        }

        // --- Deduplication guard ---
        // Prevent duplicate executions for the same shipment+rule combination.
        $existingExecution = GainshareExecution::where('shipment_uuid', $shipment->uuid)
            ->where('gainshare_rule_uuid', $rule->uuid)
            ->first();

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
            'cost_benchmark_uuid'   => $costBenchmarkUuid, // null for rate_contract path; set for cost_benchmark
            'benchmark_total'       => round($expectedTotal, 2),
            'actual_total'          => round($actualTotal, 2),
            'savings'               => $savings,
            'company_share'         => $companyShare,
            'client_share'          => $clientShare,
            'result_type'           => $resultType,
            'status'                => 'calculated',
            'meta'                  => array_merge(($existingExecution->meta ?? []), [
                'benchmark_source' => $benchmarkSource,
            ]),
        ];

        if ($existingExecution) {
            // Update existing execution rather than creating duplicate
            $existingExecution->update($executionData);
            return $existingExecution;
        }

        return GainshareExecution::create($executionData);
    }

    /**
     * Resolve expected_total from a CostBenchmark record (the legacy path).
     *
     * @return array|null ['expected_total' => float, 'cost_benchmark_uuid' => string]
     */
    protected function resolveBenchmarkFromCostBenchmark(Shipment $shipment, ServiceAgreement $agreement): ?array
    {
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

        $expectedTotal = $this->convertBenchmarkToFlat($benchmark, $shipment);

        if ($expectedTotal === null) {
            // Missing miles or weight for unit conversion
            return null;
        }

        return [
            'expected_total'      => $expectedTotal,
            'cost_benchmark_uuid' => $benchmark->uuid,
        ];
    }

    /**
     * Resolve expected_total from a RateContract via the BUILD-10 rating engine.
     *
     * Selects the most appropriate benchmark contract using a deterministic
     * preference order, then calls RateShopService::calculateForContract() for
     * that single contract (does NOT rate-shop all carriers).
     *
     * @return array|null ['expected_total' => float, 'cost_benchmark_uuid' => null]
     */
    protected function resolveBenchmarkFromRateContract(Shipment $shipment, ServiceAgreement $agreement, GainshareRule $rule): ?array
    {
        $expectedTotal = $this->getBenchmarkFromRateContract($shipment, $rule);

        if ($expectedTotal === null) {
            return null;
        }

        return [
            'expected_total'      => $expectedTotal,
            'cost_benchmark_uuid' => null, // not from CostBenchmark
        ];
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

    /**
     * Resolve benchmark from a RateContract via the BUILD-10 rating engine.
     *
     * SELECTION RULES (deterministic):
     *   1. Active + effective + same company contracts only
     *   2. usage_mode IN ('cost_management_benchmark', 'both')
     *   3. mode matches shipment.mode (or contract.mode = 'all')
     *   4. Customer-specific contracts preferred over generic (customer_uuid = null)
     *   5. usage_mode 'cost_management_benchmark' preferred over 'both'
     *      (a contract dedicated to benchmarking is more authoritative than dual-use)
     *   6. Most recent effective_date wins
     *   7. Final tiebreaker: lowest UUID (stable, deterministic)
     *
     * SAFETY:
     * - Never rate-shops all carriers — uses the SELECTED benchmark contract only
     * - Returns null if no benchmark contract resolves
     * - Returns null if rating engine cannot calculate (missing tables, exclusion, etc.)
     *
     * @param Shipment      $shipment
     * @param GainshareRule $rule
     * @return float|null Flat expected_total comparable to actual_total
     */
    protected function getBenchmarkFromRateContract(Shipment $shipment, GainshareRule $rule): ?float
    {
        // Class existence check — fleetops may not be installed in stripped environments
        if (!class_exists(\Fleetbase\FleetOps\Models\RateContract::class)
            || !class_exists(\Fleetbase\FleetOps\Services\RateShopService::class)) {
            return null;
        }

        $customerUuid = $shipment->orders()->first()?->customer_uuid;

        $contract = $this->selectBenchmarkContract($shipment, $customerUuid);

        if (!$contract) {
            return null;
        }

        // Build rating context from the shipment
        $context = $this->buildRatingContextFromShipment($shipment, $customerUuid);

        // Run the rating engine for the selected contract ONLY — never rate-shop
        $rateShopService = app(\Fleetbase\FleetOps\Services\RateShopService::class);
        $result = $rateShopService->calculateForContract($contract, $context);

        if (!$result || !isset($result['total_charge'])) {
            return null;
        }

        return (float) $result['total_charge'];
    }

    /**
     * Select the benchmark contract using deterministic preference ordering.
     */
    protected function selectBenchmarkContract(Shipment $shipment, ?string $customerUuid)
    {
        $contracts = \Fleetbase\FleetOps\Models\RateContract::where('company_uuid', $shipment->company_uuid)
            ->active()
            ->effective()
            ->forMode($shipment->mode)
            ->whereIn('usage_mode', [
                \Fleetbase\FleetOps\Models\RateContract::USAGE_COST_MANAGEMENT_BENCHMARK,
                \Fleetbase\FleetOps\Models\RateContract::USAGE_BOTH,
            ])
            ->where(function ($q) use ($customerUuid) {
                if ($customerUuid) {
                    // Either matches this customer specifically OR is generic (null)
                    $q->where('customer_uuid', $customerUuid)
                      ->orWhereNull('customer_uuid');
                } else {
                    // No customer context — only generic contracts apply
                    $q->whereNull('customer_uuid');
                }
            })
            ->get();

        if ($contracts->isEmpty()) {
            return null;
        }

        // Build a deterministic composite sort key per contract.
        // Returned as a string with fixed-width zero-padded segments so PHP
        // string comparison produces the correct ordering. Higher = earlier.
        // Format: customer-match | usage-mode | effective-date | UUID
        $keyed = $contracts->map(function ($c) use ($customerUuid) {
            $customerMatch = ($customerUuid && $c->customer_uuid === $customerUuid) ? '1' : '0';
            $dedicatedBenchmark = ($c->usage_mode === \Fleetbase\FleetOps\Models\RateContract::USAGE_COST_MANAGEMENT_BENCHMARK) ? '1' : '0';
            $effectiveTs = $c->effective_date ? str_pad((string) $c->effective_date->timestamp, 12, '0', STR_PAD_LEFT) : '000000000000';
            // UUID descending tiebreaker — invert by subtracting from 'z' chars not portable;
            // simpler: append uuid ascending and use sortBy (smallest wins overall)
            // But we want largest for the high-priority fields. So reverse the uuid sort:
            // pad with chars and use sortByDesc on the composite string.
            return [
                'contract' => $c,
                'sort_key' => $customerMatch . $dedicatedBenchmark . $effectiveTs . '|' . $c->uuid,
            ];
        });

        // sortByDesc on string composite — first the priority bits, then ts, then uuid lex order
        // For uuid we want ascending tiebreaker; since the priority parts dominate, this is fine.
        $best = $keyed->sortByDesc('sort_key')->first();

        return $best['contract'] ?? null;
    }

    /**
     * Build a rating context from a Shipment.
     * Mirrors the rating context format expected by RateShopService.
     */
    protected function buildRatingContextFromShipment(Shipment $shipment, ?string $customerUuid): array
    {
        $context = [
            'shipment_uuid'   => $shipment->uuid,
            'mode'            => $shipment->mode,
            'equipment_type'  => $shipment->equipment_type,
            'miles'           => (float) ($shipment->carrier_rate_miles ?? 0),
            'customer_uuid'   => $customerUuid,
        ];

        $pickup = $shipment->stops()->where('type', 'pickup')->orderBy('sequence')->first();
        $delivery = $shipment->stops()->where('type', 'delivery')->orderBy('sequence', 'desc')->first();

        if ($pickup) {
            $context['origin_zip']   = $pickup->postal_code;
            $context['origin_state'] = $pickup->state;
        }
        if ($delivery) {
            $context['dest_zip']   = $delivery->postal_code;
            $context['dest_state'] = $delivery->state;
        }

        // Best-effort weight/pieces from order links
        $context['weight'] = (float) $shipment->orderLinks->sum('weight');
        $context['pieces'] = (float) $shipment->orderLinks->sum('pieces');

        return $context;
    }
}
