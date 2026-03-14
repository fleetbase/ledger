<?php

namespace Fleetbase\Ledger;

use Fleetbase\Ledger\Contracts\GatewayDriverInterface;
use Fleetbase\Ledger\Gateways\CashDriver;
use Fleetbase\Ledger\Gateways\QPayDriver;
use Fleetbase\Ledger\Gateways\StripeDriver;
use Fleetbase\Ledger\Models\Gateway;
use Fleetbase\Support\Utils;
use Illuminate\Support\Manager;

/**
 * PaymentGatewayManager.
 *
 * The central orchestrator for all payment gateway operations.
 * Extends Laravel's Manager class to provide a unified, driver-based API
 * for working with multiple payment gateways.
 *
 * Usage:
 *   // Resolve a driver by gateway UUID or driver code:
 *   $driver = $manager->gateway('stripe');
 *   $driver = $manager->gateway($gatewayUuid);
 *
 *   // Use the driver:
 *   $response = $driver->purchase($purchaseRequest);
 *
 * Third-party extensions can register new drivers via the extend() method
 * in their service provider:
 *
 *   $manager->extend('paypal', function ($app) {
 *       return $app->make(\MyCompany\PaypalDriver::class);
 *   });
 */
class PaymentGatewayManager extends Manager
{
    /**
     * Resolve and initialize a gateway driver from a persisted Gateway model.
     *
     * Accepts either a Gateway UUID, a public_id (e.g., 'gateway_abc123'),
     * or a driver code (e.g., 'stripe'). The resolved driver is initialized
     * with the decrypted configuration from the Gateway model.
     *
     * @param string $gatewayIdentifier UUID, public_id, or driver code
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException if no active gateway is found
     */
    public function gateway(string $gatewayIdentifier): GatewayDriverInterface
    {
        $companyUuid = session('company');

        $gateway = Gateway::query()
            ->when($companyUuid, fn ($q) => $q->where('company_uuid', $companyUuid))
            ->where(function ($q) use ($gatewayIdentifier) {
                $q->where('uuid', $gatewayIdentifier)
                  ->orWhere('public_id', $gatewayIdentifier)
                  ->orWhere('driver', $gatewayIdentifier);
            })
            ->where('status', 'active')
            ->firstOrFail();

        return $this->driver($gateway->driver)
                    ->initialize($gateway->decryptedConfig(), $gateway->is_sandbox);
    }

    /**
     * Resolve a driver by code without loading from the database.
     * Useful for webhook handling where the gateway is identified by URL segment.
     *
     * @param string $driverCode The driver code (e.g., 'stripe', 'qpay')
     */
    public function driverForWebhook(string $driverCode, string $companyUuid): GatewayDriverInterface
    {
        $gateway = Gateway::query()
            ->where('company_uuid', $companyUuid)
            ->where('driver', $driverCode)
            ->where('status', 'active')
            ->firstOrFail();

        return $this->driver($gateway->driver)
                    ->initialize($gateway->decryptedConfig(), $gateway->is_sandbox);
    }

    /**
     * Return a list of all registered driver codes.
     * Used by the /drivers endpoint to populate the gateway configuration UI.
     *
     * @return string[]
     */
    public function getRegisteredDriverCodes(): array
    {
        return ['stripe', 'qpay', 'cash'];
    }

    /**
     * Return a list of all registered drivers with their metadata.
     * Used by the frontend to render the "Add Gateway" form dynamically.
     *
     * @return array<string, array>
     */
    public function getDriverManifest(): array
    {
        $manifest = [];

        foreach ($this->getRegisteredDriverCodes() as $code) {
            try {
                $driver       = $this->driver($code);
                $manifest[]   = [
                    'code'          => $driver->getCode(),
                    'name'          => $driver->getName(),
                    'capabilities'  => $driver->getCapabilities(),
                    'config_schema' => $driver->getConfigSchema(),
                    'webhook_url'   => Utils::apiUrl('/ledger/webhooks/' . $driver->getCode()),
                ];
            } catch (\Exception $e) {
                // Skip drivers that fail to instantiate
            }
        }

        return $manifest;
    }

    /**
     * Return the default driver code.
     */
    public function getDefaultDriver(): string
    {
        return config('ledger.default_gateway', 'cash');
    }

    // -------------------------------------------------------------------------
    // Driver Factory Methods
    // -------------------------------------------------------------------------

    /**
     * Create the Stripe driver instance.
     */
    protected function createStripeDriver(): StripeDriver
    {
        return $this->container->make(StripeDriver::class);
    }

    /**
     * Create the QPay driver instance.
     */
    protected function createQpayDriver(): QPayDriver
    {
        return $this->container->make(QPayDriver::class);
    }

    /**
     * Create the Cash driver instance.
     */
    protected function createCashDriver(): CashDriver
    {
        return $this->container->make(CashDriver::class);
    }
}
