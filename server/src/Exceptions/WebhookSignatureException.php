<?php

namespace Fleetbase\Ledger\Exceptions;

use Exception;

/**
 * WebhookSignatureException
 *
 * Thrown when a webhook request fails signature verification.
 * This indicates the request did not come from the expected gateway.
 *
 * @package Fleetbase\Ledger\Exceptions
 */
class WebhookSignatureException extends Exception
{
    public function __construct(string $gateway, string $message = '')
    {
        parent::__construct(
            sprintf(
                'Webhook signature verification failed for gateway [%s].%s',
                $gateway,
                $message ? ' ' . $message : ''
            )
        );
    }
}
