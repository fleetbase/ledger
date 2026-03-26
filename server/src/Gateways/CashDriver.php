<?php

namespace Fleetbase\Ledger\Gateways;

use Fleetbase\Ledger\DTO\GatewayResponse;
use Fleetbase\Ledger\DTO\PurchaseRequest;
use Fleetbase\Ledger\DTO\RefundRequest;
use Illuminate\Support\Str;

/**
 * CashDriver.
 *
 * A no-op payment gateway driver for cash on delivery and manual payment scenarios.
 *
 * This driver immediately marks every purchase as succeeded, representing
 * the acknowledgment that a cash payment will be collected manually.
 * It is also used as the default fallback driver in the system.
 *
 * Use cases:
 *   - Cash on delivery (COD) for FleetOps orders
 *   - Manual invoice payments recorded by an operator
 *   - Testing and development without a real payment gateway
 */
class CashDriver extends AbstractGatewayDriver
{
    public function getName(): string
    {
        return 'Cash / Manual';
    }

    public function getCode(): string
    {
        return 'cash';
    }

    public function getCapabilities(): array
    {
        return [
            'purchase',
            'refund',
        ];
    }

    public function getConfigSchema(): array
    {
        return [
            [
                'key'      => 'label',
                'label'    => 'Display Label',
                'type'     => 'text',
                'required' => false,
                'hint'     => 'Optional label shown to customers, e.g. "Cash on Delivery" or "Pay at Counter".',
            ],
            [
                'key'      => 'instructions',
                'label'    => 'Payment Instructions',
                'type'     => 'textarea',
                'required' => false,
                'hint'     => 'Instructions shown to customers after selecting this payment method.',
            ],
        ];
    }

    /**
     * {@inheritdoc}
     *
     * Immediately marks the purchase as succeeded.
     * No external API call is made.
     */
    public function purchase(PurchaseRequest $request): GatewayResponse
    {
        // Generate a local reference ID for traceability
        $referenceId = 'cash_' . Str::uuid()->toString();

        $this->logInfo('Cash purchase recorded', [
            'reference_id' => $referenceId,
            'amount'       => $request->amount,
            'currency'     => $request->currency,
            'description'  => $request->description,
        ]);

        return GatewayResponse::success(
            gatewayTransactionId: $referenceId,
            eventType: GatewayResponse::EVENT_PAYMENT_SUCCEEDED,
            message: $this->config('instructions') ?? 'Cash payment recorded. Collect payment manually.',
            amount: $request->amount,
            currency: $request->currency,
            data: [
                'reference_id' => $referenceId,
                'label'        => $this->config('label') ?? 'Cash / Manual',
            ],
        );
    }

    /**
     * {@inheritdoc}
     *
     * Records a manual refund. No external API call is made.
     */
    public function refund(RefundRequest $request): GatewayResponse
    {
        $refundReferenceId = 'cash_refund_' . Str::uuid()->toString();

        $this->logInfo('Cash refund recorded', [
            'original_reference_id' => $request->gatewayTransactionId,
            'refund_reference_id'   => $refundReferenceId,
            'amount'                => $request->amount,
            'reason'                => $request->reason,
        ]);

        return GatewayResponse::success(
            gatewayTransactionId: $refundReferenceId,
            eventType: GatewayResponse::EVENT_REFUND_PROCESSED,
            message: 'Cash refund recorded. Return payment to customer manually.',
            amount: $request->amount,
            currency: $request->currency,
            data: [
                'original_reference_id' => $request->gatewayTransactionId,
                'refund_reference_id'   => $refundReferenceId,
            ],
        );
    }
}
