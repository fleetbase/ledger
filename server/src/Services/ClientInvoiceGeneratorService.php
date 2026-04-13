<?php

namespace Fleetbase\Ledger\Services;

use Fleetbase\FleetOps\Models\Shipment;
use Fleetbase\Ledger\Models\ClientInvoice;
use Fleetbase\Ledger\Models\ClientInvoiceItem;
use Fleetbase\Ledger\Models\ServiceAgreement;
use Fleetbase\Ledger\Models\ServiceAgreementCharge;

/**
 * Generates client invoices from shipments using service agreement charge templates.
 *
 * This service is the core billing engine. It:
 * 1. Finds the applicable charge template(s) for a service agreement
 * 2. Iterates charge_template_items
 * 3. Calculates each line item according to its method
 * 4. Applies per-client overrides from service_agreement_charges.overrides
 * 5. Creates the ClientInvoice with calculated totals
 *
 * It does NOT:
 * - Modify shipment execution state
 * - Trigger routing or tendering
 * - Interact with carrier invoices or GL assignment
 * - Send notifications or emails
 */
class ClientInvoiceGeneratorService
{
    protected InvoiceNumberGenerator $numberGenerator;

    public function __construct(InvoiceNumberGenerator $numberGenerator)
    {
        $this->numberGenerator = $numberGenerator;
    }

    /**
     * Generate a client invoice for a single shipment.
     *
     * @param Shipment         $shipment  The delivered/completed shipment
     * @param ServiceAgreement $agreement The active service agreement for the customer
     * @return ClientInvoice The generated invoice with items
     */
    public function generateForShipment(Shipment $shipment, ServiceAgreement $agreement): ClientInvoice
    {
        $charges = $agreement->charges()
            ->active()
            ->with('chargeTemplate.items')
            ->get();

        $invoice = ClientInvoice::create([
            'company_uuid'           => $shipment->company_uuid,
            'customer_uuid'          => $agreement->customer_uuid,
            'service_agreement_uuid' => $agreement->uuid,
            'shipment_uuid'          => $shipment->uuid,
            'invoice_number'         => $this->numberGenerator->generate($shipment->company_uuid),
            'status'                 => 'draft',
            'invoice_date'           => now()->toDateString(),
            'due_date'               => now()->addDays($agreement->payment_terms_days)->toDateString(),
            'currency'               => $agreement->currency,
        ]);

        $subtotal = 0;

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

                $calculated = $this->calculateCharge($method, $rate, $shipment, $subtotal);

                // Apply min/max caps
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
                    'description'         => $templateItem->description,
                    'calculation_method'  => $method,
                    'rate'                => $rate,
                    'quantity'            => $calculated['quantity'],
                    'amount'              => $amount,
                    'shipment_uuid'       => $shipment->uuid,
                ]);

                $subtotal += $amount;
            }
        }

        $invoice->update([
            'subtotal'     => round($subtotal, 2),
            'total_amount' => round($subtotal, 2), // tax_amount stays 0 for now
        ]);

        return $invoice->load('items');
    }

    /**
     * Calculate a charge amount based on the calculation method.
     *
     * @param string   $method           Calculation method
     * @param float    $rate             Rate value
     * @param Shipment $shipment         Source shipment for context
     * @param float    $runningSubtotal  Running subtotal for percentage_of_total
     * @return array ['amount' => float, 'quantity' => float|null]
     */
    protected function calculateCharge(string $method, ?float $rate, Shipment $shipment, float $runningSubtotal): array
    {
        if ($rate === null) {
            return ['amount' => 0, 'quantity' => null];
        }

        return match ($method) {
            'flat' => [
                'amount'   => $rate,
                'quantity' => 1,
            ],
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
            default => [
                'amount'   => $rate,
                'quantity' => null,
            ],
        };
    }
}
