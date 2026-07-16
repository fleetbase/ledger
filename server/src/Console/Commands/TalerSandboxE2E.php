<?php

namespace Fleetbase\Ledger\Console\Commands;

use Fleetbase\Ledger\Gateways\TalerDriver;
use Illuminate\Console\Command;

class TalerSandboxE2E extends Command
{
    protected $signature = 'ledger:taler:e2e
                            {--amount=1 : Amount in the smallest currency unit}
                            {--currency=KUDOS : Taler sandbox currency}';

    protected $description = 'Run the opt-in GNU Taler sandbox E2E bootstrap flow.';

    public function handle(): int
    {
        if (!filter_var(env('TALER_E2E_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->warn('[Ledger/Taler] E2E skipped. Set TALER_E2E_ENABLED=true to run against a live sandbox.');

            return self::SUCCESS;
        }

        $backendUrl = env('TALER_E2E_BACKEND_URL');
        $instanceId = env('TALER_E2E_INSTANCE_ID', 'default');
        $apiToken   = env('TALER_E2E_API_TOKEN');

        if (!$backendUrl || !$apiToken) {
            $this->error('[Ledger/Taler] TALER_E2E_BACKEND_URL and TALER_E2E_API_TOKEN are required.');

            return self::FAILURE;
        }

        $driver = app(TalerDriver::class)->initialize([
            'backend_url' => $backendUrl,
            'instance_id' => $instanceId,
            'api_token'   => $apiToken,
        ], true);

        $credentials = $driver->testCredentials();
        if (!($credentials['ok'] ?? false)) {
            $this->error('[Ledger/Taler] Credential check failed: ' . ($credentials['message'] ?? 'unknown error'));

            return self::FAILURE;
        }

        $response = $driver->createTestOrder([
            'amount'      => (int) $this->option('amount'),
            'currency'    => strtoupper((string) $this->option('currency')),
            'description' => 'Ledger GNU Taler sandbox E2E order',
            'metadata'    => [
                'company_uuid' => env('TALER_E2E_COMPANY_UUID'),
                'e2e'          => true,
            ],
        ]);

        if ($response->isFailed()) {
            $this->error('[Ledger/Taler] Test order creation failed: ' . $response->message);

            return self::FAILURE;
        }

        $this->info('[Ledger/Taler] Sandbox E2E test order created.');
        $this->line('Order ID: ' . $response->gatewayTransactionId);
        $this->line('Payment URI: ' . ($response->data['taler_pay_uri'] ?? 'n/a'));
        $this->line('Next: complete the wallet payment, then run webhook/settlement verification and record the evidence in docs/taler/release-evidence.md.');

        return self::SUCCESS;
    }
}
