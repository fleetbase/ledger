<?php

namespace Fleetbase\Ledger\Events;

use Fleetbase\Ledger\DTO\GatewayResponse;
use Fleetbase\Ledger\Models\Gateway;
use Fleetbase\Ledger\Models\GatewayTransaction;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * RefundProcessed Event.
 *
 * Dispatched when a refund has been successfully processed.
 *
 * Listeners can use this event to:
 *   - Mark an invoice as refunded
 *   - Debit a wallet if the original payment was to a wallet
 *   - Send a refund confirmation email to the customer
 *   - Create a reversal journal entry
 */
class RefundProcessed
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param GatewayResponse    $response           The normalized gateway response
     * @param Gateway            $gateway            The gateway that processed the refund
     * @param GatewayTransaction $gatewayTransaction The persisted gateway transaction record
     */
    public function __construct(
        public readonly GatewayResponse $response,
        public readonly Gateway $gateway,
        public readonly GatewayTransaction $gatewayTransaction,
    ) {
    }
}
