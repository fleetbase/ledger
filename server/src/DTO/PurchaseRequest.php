<?php

namespace Fleetbase\Ledger\DTO;

/**
 * PurchaseRequest DTO
 *
 * Immutable data transfer object for initiating a payment charge.
 * All monetary amounts must be in the smallest currency unit (e.g., cents for USD).
 *
 * @package Fleetbase\Ledger\DTO
 */
final class PurchaseRequest
{
    /**
     * @param int         $amount              Amount in smallest currency unit (e.g., 100 = $1.00 USD)
     * @param string      $currency            ISO 4217 currency code (e.g., 'USD', 'MNT', 'SGD')
     * @param string      $description         Human-readable description of the charge
     * @param string|null $paymentMethodToken  Stored payment method token (for tokenized payments)
     * @param string|null $customerId          Gateway-side customer ID (e.g., Stripe cus_xxx)
     * @param string|null $customerEmail       Customer email for receipt and gateway records
     * @param string|null $invoiceUuid         UUID of the related Ledger Invoice
     * @param string|null $orderUuid           UUID of the related FleetOps Order
     * @param string|null $returnUrl           URL to redirect to after off-site payment (e.g., QPay)
     * @param string|null $cancelUrl           URL to redirect to if payment is cancelled
     * @param array       $metadata            Arbitrary key-value metadata passed to the gateway
     */
    public function __construct(
        public readonly int     $amount,
        public readonly string  $currency,
        public readonly string  $description,
        public readonly ?string $paymentMethodToken = null,
        public readonly ?string $customerId = null,
        public readonly ?string $customerEmail = null,
        public readonly ?string $invoiceUuid = null,
        public readonly ?string $orderUuid = null,
        public readonly ?string $returnUrl = null,
        public readonly ?string $cancelUrl = null,
        public readonly array   $metadata = [],
    ) {
    }

    /**
     * Return the amount formatted as a decimal string for display purposes.
     * For example, 10050 cents in USD returns "100.50".
     *
     * Note: This is for display only. Always use $amount (integer) for calculations.
     */
    public function getFormattedAmount(int $decimalPlaces = 2): string
    {
        return number_format($this->amount / (10 ** $decimalPlaces), $decimalPlaces);
    }
}
