<?php

namespace Fleetbase\Ledger\Events;

use Fleetbase\Ledger\DTO\GatewayResponse;
use Fleetbase\Ledger\Models\Gateway;
use Fleetbase\Ledger\Models\GatewayTransaction;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * PaymentFailed Event.
 *
 * Dispatched when a payment attempt has failed.
 *
 * Listeners can use this event to:
 *   - Mark an invoice as overdue or failed
 *   - Notify the customer of the failed payment
 *   - Retry the payment with a different method
 *   - Alert the operations team
 */
class PaymentFailed
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param GatewayResponse    $response           The normalized gateway response
     * @param Gateway            $gateway            The gateway that attempted the payment
     * @param GatewayTransaction $gatewayTransaction The persisted gateway transaction record
     */
    public function __construct(
        public readonly GatewayResponse $response,
        public readonly Gateway $gateway,
        public readonly GatewayTransaction $gatewayTransaction,
    ) {
    }
}
