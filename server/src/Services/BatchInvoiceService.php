<?php

namespace Fleetbase\Ledger\Services;

use Carbon\Carbon;
use Fleetbase\FleetOps\Models\Shipment;
use Fleetbase\Ledger\Models\ClientInvoice;
use Fleetbase\Ledger\Models\ClientInvoiceItem;
use Fleetbase\Ledger\Models\ServiceAgreement;
use Illuminate\Support\Collection;

/**
 * Generates batch/consolidated invoices for billing periods.
 *
 * For service agreements with billing_frequency = 'weekly', 'biweekly', or 'monthly',
 * this service groups delivered shipments by customer and generates one consolidated
 * invoice per customer for the period.
 *
 * Does NOT modify shipment state or trigger side effects.
 */
class BatchInvoiceService
{
    protected InvoiceNumberGenerator $numberGenerator;
    protected ClientInvoiceGeneratorService $invoiceGenerator;

    public function __construct(
        InvoiceNumberGenerator $numberGenerator,
        ClientInvoiceGeneratorService $invoiceGenerator
    ) {
        $this->numberGenerator = $numberGenerator;
        $this->invoiceGenerator = $invoiceGenerator;
    }

    /**
     * Generate batch invoices for a company and billing period.
     *
     * Groups delivered/completed shipments by customer, finds active service
     * agreements, and generates one consolidated invoice per customer.
     *
     * @param string $companyUuid The company generating invoices
     * @param Carbon $periodStart Start of billing period
     * @param Carbon $periodEnd   End of billing period
     * @return Collection<ClientInvoice> Generated invoices
     */
    public function generateBatch(string $companyUuid, Carbon $periodStart, Carbon $periodEnd): Collection
    {
        $invoices = collect();

        // Find all batch-eligible service agreements
        $agreements = ServiceAgreement::where('company_uuid', $companyUuid)
            ->active()
            ->effective()
            ->whereIn('billing_frequency', ['weekly', 'biweekly', 'monthly'])
            ->get();

        foreach ($agreements as $agreement) {
            $customerUuid = $agreement->customer_uuid;

            // Find delivered/completed shipments for this customer in the period
            $shipments = Shipment::where('company_uuid', $companyUuid)
                ->whereIn('status', ['delivered', 'completed'])
                ->whereBetween('actual_delivery_at', [$periodStart, $periodEnd])
                ->whereHas('orders', function ($q) use ($customerUuid) {
                    $q->where('customer_uuid', $customerUuid);
                })
                ->whereDoesntHave('meta', function ($q) {
                    // Skip shipments already invoiced
                })
                ->get();

            // Filter out already-invoiced shipments
            $uninvoicedShipments = $shipments->filter(function ($shipment) {
                return !ClientInvoiceItem::where('shipment_uuid', $shipment->uuid)->exists();
            });

            if ($uninvoicedShipments->isEmpty()) {
                continue;
            }

            $invoice = $this->generateConsolidatedInvoice(
                $agreement,
                $uninvoicedShipments,
                $periodStart,
                $periodEnd
            );

            if ($invoice) {
                $invoices->push($invoice);
            }
        }

        return $invoices;
    }

    /**
     * Generate a single consolidated invoice for multiple shipments under one agreement.
     */
    protected function generateConsolidatedInvoice(
        ServiceAgreement $agreement,
        Collection $shipments,
        Carbon $periodStart,
        Carbon $periodEnd
    ): ?ClientInvoice {
        $companyUuid = $agreement->company_uuid;

        $charges = $agreement->charges()
            ->active()
            ->with('chargeTemplate.items')
            ->get();

        if ($charges->isEmpty()) {
            return null;
        }

        $invoice = ClientInvoice::create([
            'company_uuid'           => $companyUuid,
            'customer_uuid'          => $agreement->customer_uuid,
            'service_agreement_uuid' => $agreement->uuid,
            'invoice_number'         => $this->numberGenerator->generate($companyUuid),
            'status'                 => 'draft',
            'invoice_date'           => now()->toDateString(),
            'due_date'               => now()->addDays($agreement->payment_terms_days)->toDateString(),
            'period_start'           => $periodStart->toDateString(),
            'period_end'             => $periodEnd->toDateString(),
            'currency'               => $agreement->currency,
        ]);

        $grandTotal = 0;

        foreach ($shipments as $shipment) {
            $shipmentSubtotal = 0;

            foreach ($charges as $charge) {
                $template = $charge->chargeTemplate;
                if (!$template) continue;

                $overrides = $charge->overrides ?? [];

                foreach ($template->items()->active()->orderBy('sequence')->get() as $templateItem) {
                    $itemOverride = $overrides[$templateItem->charge_type] ?? [];
                    $rate = $itemOverride['rate'] ?? $templateItem->rate;
                    $method = $itemOverride['calculation_method'] ?? $templateItem->calculation_method;
                    $minimum = $itemOverride['minimum'] ?? $templateItem->minimum;
                    $maximum = $itemOverride['maximum'] ?? $templateItem->maximum;

                    $calculated = $this->calculateCharge($method, $rate, $shipment, $shipmentSubtotal);

                    if ($minimum !== null && $calculated['amount'] < $minimum) {
                        $calculated['amount'] = $minimum;
                    }
                    if ($maximum !== null && $calculated['amount'] > $maximum) {
                        $calculated['amount'] = $maximum;
                    }

                    $amount = round($calculated['amount'], 2);

                    ClientInvoiceItem::create([
                        'client_invoice_uuid' => $invoice->uuid,
                        'charge_type'         => $templateItem->charge_type,
                        'description'         => $templateItem->description . ' — Shipment ' . ($shipment->public_id ?? $shipment->uuid),
                        'calculation_method'  => $method,
                        'rate'                => $rate,
                        'quantity'            => $calculated['quantity'],
                        'amount'              => $amount,
                        'shipment_uuid'       => $shipment->uuid,
                    ]);

                    $shipmentSubtotal += $amount;
                }
            }

            $grandTotal += $shipmentSubtotal;
        }

        $invoice->update([
            'subtotal'     => round($grandTotal, 2),
            'total_amount' => round($grandTotal, 2),
        ]);

        return $invoice->load('items');
    }

    /**
     * Same calculation logic as ClientInvoiceGeneratorService.
     */
    protected function calculateCharge(string $method, ?float $rate, Shipment $shipment, float $runningSubtotal): array
    {
        if ($rate === null) {
            return ['amount' => 0, 'quantity' => null];
        }

        return match ($method) {
            'flat' => ['amount' => $rate, 'quantity' => 1],
            'per_mile' => [
                'amount'   => ($shipment->carrier_rate_miles ?? 0) * $rate,
                'quantity' => $shipment->carrier_rate_miles,
            ],
            'per_cwt' => [
                'amount'   => (($shipment->total_weight ?? 0) / 100) * $rate,
                'quantity' => ($shipment->total_weight ?? 0) / 100,
            ],
            'per_unit' => [
                'amount'   => ($shipment->total_pieces ?? 0) * $rate,
                'quantity' => $shipment->total_pieces,
            ],
            'percentage_of_linehaul' => [
                'amount'   => ($shipment->carrier_rate ?? 0) * ($rate / 100),
                'quantity' => null,
            ],
            'percentage_of_total' => [
                'amount'   => $runningSubtotal * ($rate / 100),
                'quantity' => null,
            ],
            default => ['amount' => $rate, 'quantity' => null],
        };
    }
}
