<?php

namespace Fleetbase\Ledger\DTO;

/**
 * RefundRequest DTO.
 *
 * Immutable data transfer object for initiating a refund.
 * All monetary amounts must be in the smallest currency unit (e.g., cents for USD).
 */
final class RefundRequest
{
    /**
     * @param string      $gatewayTransactionId The original transaction reference from the gateway (e.g., Stripe ch_xxx)
     * @param int         $amount               Amount to refund in smallest currency unit. If null, full refund.
     * @param string      $currency             ISO 4217 currency code
     * @param string|null $reason               Reason for the refund: 'duplicate', 'fraudulent', 'requested_by_customer'
     * @param string|null $invoiceUuid          UUID of the related Ledger Invoice
     * @param array       $metadata             Arbitrary key-value metadata passed to the gateway
     */
    public function __construct(
        public readonly string $gatewayTransactionId,
        public readonly int $amount,
        public readonly string $currency,
        public readonly ?string $reason = null,
        public readonly ?string $invoiceUuid = null,
        public readonly array $metadata = [],
    ) {
    }
}
