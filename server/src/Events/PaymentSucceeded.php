<?php

namespace Fleetbase\Ledger\Events;

use Fleetbase\Ledger\DTO\GatewayResponse;
use Fleetbase\Ledger\Models\Gateway;
use Fleetbase\Ledger\Models\GatewayTransaction;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * PaymentSucceeded Event
 *
 * Dispatched when a payment has been successfully confirmed, either via
 * a direct API response (Stripe PaymentIntent succeeded) or via a webhook
 * callback (QPay payment confirmed).
 *
 * Listeners can use this event to:
 *   - Mark an invoice as paid
 *   - Credit a wallet
 *   - Send a payment receipt email
 *   - Update order status in FleetOps
 *   - Create a journal entry for the revenue
 *
 * @package Fleetbase\Ledger\Events
 */
class PaymentSucceeded
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param GatewayResponse    $response           The normalized gateway response
     * @param Gateway            $gateway            The gateway that processed the payment
     * @param GatewayTransaction $gatewayTransaction The persisted gateway transaction record
     */
    public function __construct(
        public readonly GatewayResponse    $response,
        public readonly Gateway            $gateway,
        public readonly GatewayTransaction $gatewayTransaction,
    ) {
    }
}
