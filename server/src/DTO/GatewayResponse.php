<?php

namespace Fleetbase\Ledger\DTO;

/**
 * GatewayResponse DTO.
 *
 * Standardized, immutable response object returned by all gateway driver methods.
 * This ensures that regardless of which gateway is used, the calling code always
 * receives data in the same structure.
 */
final class GatewayResponse
{
    /**
     * Normalized event type constants.
     * These are used in the eventType field to allow consistent event dispatching
     * regardless of the underlying gateway's own event naming conventions.
     */
    public const EVENT_PAYMENT_SUCCEEDED  = 'payment.succeeded';
    public const EVENT_PAYMENT_FAILED     = 'payment.failed';
    public const EVENT_PAYMENT_PENDING    = 'payment.pending';
    public const EVENT_REFUND_PROCESSED   = 'refund.processed';
    public const EVENT_REFUND_FAILED      = 'refund.failed';
    public const EVENT_SETUP_SUCCEEDED    = 'setup.succeeded';
    public const EVENT_CHARGEBACK         = 'chargeback.created';
    public const EVENT_UNKNOWN            = 'unknown';

    /**
     * Transaction status constants.
     */
    public const STATUS_PENDING   = 'pending';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_REFUNDED  = 'refunded';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @param bool        $successful           Whether the operation was successful
     * @param string      $gatewayTransactionId The unique transaction reference from the gateway
     * @param string      $status               Normalized status: 'pending', 'succeeded', 'failed', 'refunded'
     * @param string      $eventType            Normalized event type (use EVENT_* constants)
     * @param string|null $message              Human-readable message (success or error)
     * @param string|null $errorCode            Gateway-specific error code for debugging
     * @param int|null    $amount               Amount in smallest currency unit (if returned by gateway)
     * @param string|null $currency             ISO 4217 currency code (if returned by gateway)
     * @param array       $rawResponse          The full, raw response from the gateway for debugging
     * @param array       $data                 Additional normalized data (e.g., payment URLs for QPay)
     */
    public function __construct(
        public readonly bool $successful,
        public readonly string $gatewayTransactionId,
        public readonly string $status,
        public readonly string $eventType,
        public readonly ?string $message = null,
        public readonly ?string $errorCode = null,
        public readonly ?int $amount = null,
        public readonly ?string $currency = null,
        public readonly array $rawResponse = [],
        public readonly array $data = [],
    ) {
    }

    /**
     * Convenience method to check if the operation was successful.
     */
    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    /**
     * Convenience method to check if the operation failed.
     */
    public function isFailed(): bool
    {
        return !$this->successful;
    }

    /**
     * Convenience method to check if the payment is pending (e.g., awaiting webhook confirmation).
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Create a successful response.
     */
    public static function success(
        string $gatewayTransactionId,
        string $eventType = self::EVENT_PAYMENT_SUCCEEDED,
        ?string $message = null,
        ?int $amount = null,
        ?string $currency = null,
        array $rawResponse = [],
        array $data = [],
    ): self {
        return new self(
            successful: true,
            gatewayTransactionId: $gatewayTransactionId,
            status: self::STATUS_SUCCEEDED,
            eventType: $eventType,
            message: $message,
            amount: $amount,
            currency: $currency,
            rawResponse: $rawResponse,
            data: $data,
        );
    }

    /**
     * Create a pending response (payment initiated but not yet confirmed).
     */
    public static function pending(
        string $gatewayTransactionId,
        string $eventType = self::EVENT_PAYMENT_PENDING,
        ?string $message = null,
        array $rawResponse = [],
        array $data = [],
    ): self {
        return new self(
            successful: true,
            gatewayTransactionId: $gatewayTransactionId,
            status: self::STATUS_PENDING,
            eventType: $eventType,
            message: $message,
            rawResponse: $rawResponse,
            data: $data,
        );
    }

    /**
     * Create a failed response.
     */
    public static function failure(
        string $gatewayTransactionId = '',
        string $eventType = self::EVENT_PAYMENT_FAILED,
        ?string $message = null,
        ?string $errorCode = null,
        array $rawResponse = [],
    ): self {
        return new self(
            successful: false,
            gatewayTransactionId: $gatewayTransactionId,
            status: self::STATUS_FAILED,
            eventType: $eventType,
            message: $message,
            errorCode: $errorCode,
            rawResponse: $rawResponse,
        );
    }
}
