<?php

namespace Fleetbase\Ledger\Gateways;

use Fleetbase\Ledger\Contracts\GatewayDriverInterface;
use Fleetbase\Ledger\DTO\GatewayResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * AbstractGatewayDriver
 *
 * Base class for all payment gateway drivers. Provides shared utilities,
 * default implementations, and enforces the GatewayDriverInterface contract.
 *
 * All concrete gateway drivers should extend this class rather than
 * implementing GatewayDriverInterface directly.
 *
 * @package Fleetbase\Ledger\Gateways
 */
abstract class AbstractGatewayDriver implements GatewayDriverInterface
{
    /**
     * The decrypted configuration array from the Gateway model.
     *
     * @var array<string, mixed>
     */
    protected array $config = [];

    /**
     * Whether this driver is operating in sandbox/test mode.
     */
    protected bool $sandbox = false;

    /**
     * Initialize the driver with configuration from the persisted Gateway model.
     *
     * @param array $config  Decrypted key-value configuration
     * @param bool  $sandbox Whether to use sandbox/test mode
     *
     * @return static
     */
    public function initialize(array $config, bool $sandbox = false): static
    {
        $this->config  = $config;
        $this->sandbox = $sandbox;

        return $this;
    }

    /**
     * Retrieve a configuration value by key.
     *
     * @param string $key     The config key
     * @param mixed  $default Default value if key is not set
     *
     * @return mixed
     */
    protected function config(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Check whether the driver is in sandbox mode.
     */
    protected function isSandbox(): bool
    {
        return $this->sandbox;
    }

    /**
     * Log an error message prefixed with the gateway code.
     *
     * @param string $message The error message
     * @param array  $context Additional context for the log entry
     */
    protected function logError(string $message, array $context = []): void
    {
        Log::channel('ledger')->error("[{$this->getCode()}] {$message}", $context);
    }

    /**
     * Log an info message prefixed with the gateway code.
     *
     * @param string $message The info message
     * @param array  $context Additional context for the log entry
     */
    protected function logInfo(string $message, array $context = []): void
    {
        Log::channel('ledger')->info("[{$this->getCode()}] {$message}", $context);
    }

    /**
     * Default no-op implementation of createPaymentMethod.
     *
     * Drivers that support tokenization should override this method.
     * Drivers that do not support tokenization will throw a RuntimeException
     * if this method is called, which serves as a clear developer error signal.
     *
     * @param array $data Gateway-specific tokenization data
     *
     * @return GatewayResponse
     *
     * @throws \RuntimeException
     */
    public function createPaymentMethod(array $data): GatewayResponse
    {
        throw new \RuntimeException(
            sprintf(
                'Gateway [%s] does not support payment method tokenization. ' .
                'Check getCapabilities() before calling createPaymentMethod().',
                $this->getCode()
            )
        );
    }

    /**
     * Default no-op implementation of handleWebhook.
     *
     * Drivers that support webhooks should override this method.
     *
     * @param Request $request The incoming HTTP request
     *
     * @return GatewayResponse
     */
    public function handleWebhook(Request $request): GatewayResponse
    {
        return GatewayResponse::failure(
            eventType: GatewayResponse::EVENT_UNKNOWN,
            message:   sprintf('Gateway [%s] does not support webhooks.', $this->getCode()),
        );
    }

    /**
     * Check if this driver has a specific capability.
     *
     * @param string $capability The capability to check (e.g., 'refund', 'tokenization')
     *
     * @return bool
     */
    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->getCapabilities(), true);
    }
}
