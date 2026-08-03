<?php

use Fleetbase\Ledger\Console\Commands\TalerSandboxE2E;
use Fleetbase\Ledger\DTO\GatewayResponse;
use Fleetbase\Ledger\Gateways\TalerDriver;
use Illuminate\Container\Container;
use Symfony\Component\Console\Tester\CommandTester;

final class TalerSandboxE2EDriverSpy extends TalerDriver
{
    public array $initializations        = [];
    public array $credentialResult       = ['ok' => true];
    public ?GatewayResponse $orderResult = null;
    public array $orders                 = [];

    public function initialize(array $config, bool $sandbox = false): static
    {
        $this->initializations[] = compact('config', 'sandbox');

        return $this;
    }

    public function testCredentials(): array
    {
        return $this->credentialResult;
    }

    public function createTestOrder(array $options = []): GatewayResponse
    {
        $this->orders[] = $options;

        return $this->orderResult ?? GatewayResponse::pending(
            'taler-e2e-order',
            data: ['taler_pay_uri' => 'taler://pay/e2e'],
        );
    }
}

function talerE2ETester(TalerSandboxE2EDriverSpy $driver): CommandTester
{
    Container::getInstance()->instance(TalerDriver::class, $driver);
    $command = new TalerSandboxE2E();
    $command->setLaravel(Container::getInstance());

    return new CommandTester($command);
}

function setTalerE2EEnvironment(array $values): void
{
    foreach ([
        'TALER_E2E_ENABLED',
        'TALER_E2E_BACKEND_URL',
        'TALER_E2E_INSTANCE_ID',
        'TALER_E2E_API_TOKEN',
        'TALER_E2E_COMPANY_UUID',
    ] as $key) {
        $value = $values[$key] ?? null;

        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            putenv("{$key}={$value}");
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
        }
    }
}

beforeEach(function () {
    setTalerE2EEnvironment([]);
});

afterEach(function () {
    setTalerE2EEnvironment([]);
});

test('Taler sandbox E2E is safely opt in', function () {
    $driver = new TalerSandboxE2EDriverSpy();
    $tester = talerE2ETester($driver);

    expect($tester->execute([]))->toBe(0)
        ->and($driver->initializations)->toBe([])
        ->and($tester->getDisplay())->toContain('E2E skipped');
});

test('Taler sandbox E2E requires backend and API token configuration', function () {
    setTalerE2EEnvironment(['TALER_E2E_ENABLED' => 'true']);
    $tester = talerE2ETester(new TalerSandboxE2EDriverSpy());

    expect($tester->execute([]))->toBe(1)
        ->and($tester->getDisplay())->toContain('TALER_E2E_BACKEND_URL and TALER_E2E_API_TOKEN are required');
});

test('Taler sandbox E2E reports credential diagnostic failures', function () {
    setTalerE2EEnvironment([
        'TALER_E2E_ENABLED'     => 'true',
        'TALER_E2E_BACKEND_URL' => 'https://backend.test',
        'TALER_E2E_API_TOKEN'   => 'secret',
    ]);
    $driver                   = new TalerSandboxE2EDriverSpy();
    $driver->credentialResult = ['ok' => false, 'message' => 'token rejected'];
    $tester                   = talerE2ETester($driver);

    expect($tester->execute([]))->toBe(1)
        ->and($driver->initializations[0])->toBe([
            'config' => [
                'backend_url' => 'https://backend.test',
                'instance_id' => 'default',
                'api_token'   => 'secret',
            ],
            'sandbox' => true,
        ])
        ->and($tester->getDisplay())->toContain('Credential check failed: token rejected');
});

test('Taler sandbox E2E maps provider order failures', function () {
    setTalerE2EEnvironment([
        'TALER_E2E_ENABLED'     => 'true',
        'TALER_E2E_BACKEND_URL' => 'https://backend.test',
        'TALER_E2E_INSTANCE_ID' => 'merchant',
        'TALER_E2E_API_TOKEN'   => 'secret',
    ]);
    $driver              = new TalerSandboxE2EDriverSpy();
    $driver->orderResult = GatewayResponse::failure(message: 'order rejected');
    $tester              = talerE2ETester($driver);

    expect($tester->execute([]))->toBe(1)
        ->and($tester->getDisplay())->toContain('Test order creation failed: order rejected');
});

test('Taler sandbox E2E creates a normalized test order and prints wallet handoff', function () {
    setTalerE2EEnvironment([
        'TALER_E2E_ENABLED'      => 'true',
        'TALER_E2E_BACKEND_URL'  => 'https://backend.test',
        'TALER_E2E_API_TOKEN'    => 'secret',
        'TALER_E2E_COMPANY_UUID' => 'company-1',
    ]);
    $driver = new TalerSandboxE2EDriverSpy();
    $tester = talerE2ETester($driver);

    expect($tester->execute(['--amount' => '25', '--currency' => 'kudos']))->toBe(0)
        ->and($driver->orders)->toBe([[
            'amount'      => 25,
            'currency'    => 'KUDOS',
            'description' => 'Ledger GNU Taler sandbox E2E order',
            'metadata'    => ['company_uuid' => 'company-1', 'e2e' => true],
        ]])
        ->and($tester->getDisplay())
        ->toContain('Sandbox E2E test order created.')
        ->toContain('Order ID: taler-e2e-order')
        ->toContain('Payment URI: taler://pay/e2e')
        ->toContain('release-evidence.md');
});
