<?php

use Fleetbase\Ledger\Console\Commands\BackfillTransactionDirection;
use Fleetbase\Ledger\Console\Commands\ProvisionLedgerDefaults;
use Fleetbase\Ledger\Console\Commands\RepairRevenueLifecycle;
use Fleetbase\Ledger\Console\Commands\TalerSandboxE2E;
use Fleetbase\Ledger\Console\Commands\UpdateOverdueInvoices;
use Fleetbase\Ledger\Console\Commands\VerifyTalerRefunds;
use Fleetbase\Ledger\Console\Commands\VerifyTalerSettlements;
use Fleetbase\Ledger\Events\PaymentFailed;
use Fleetbase\Ledger\Events\PaymentSucceeded;
use Fleetbase\Ledger\Events\RefundProcessed;
use Fleetbase\Ledger\Listeners\HandleFailedPayment;
use Fleetbase\Ledger\Listeners\HandleProcessedRefund;
use Fleetbase\Ledger\Listeners\HandleSuccessfulPayment;
use Fleetbase\Ledger\PaymentGatewayManager;
use Fleetbase\Ledger\Providers\LedgerServiceProvider;
use Fleetbase\Ledger\Services\InvoiceService;
use Fleetbase\Ledger\Services\LedgerService;
use Fleetbase\Ledger\Services\PaymentService;
use Fleetbase\Ledger\Services\RevenueLifecycleService;
use Fleetbase\Ledger\Services\TalerRefundVerificationService;
use Fleetbase\Ledger\Services\WalletService;
use Fleetbase\Providers\CoreServiceProvider;
use Fleetbase\Services\TemplateRenderService;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;

final class LedgerEventDispatcherSpy
{
    public array $listeners = [];

    public function listen($events, $listener = null): void
    {
        $this->listeners[$events] = $listener;
    }
}

final class LedgerScheduleTaskSpy
{
    public array $calls = [];

    public function everyFifteenMinutes(): self
    {
        $this->calls[] = ['everyFifteenMinutes'];

        return $this;
    }

    public function name(string $name): self
    {
        $this->calls[] = ['name', $name];

        return $this;
    }

    public function withoutOverlapping(): self
    {
        $this->calls[] = ['withoutOverlapping'];

        return $this;
    }
}

final class LedgerScheduleSpy
{
    public array $commands = [];
    public LedgerScheduleTaskSpy $task;

    public function __construct()
    {
        $this->task = new LedgerScheduleTaskSpy();
    }

    public function command(string $command): LedgerScheduleTaskSpy
    {
        $this->commands[] = $command;

        return $this->task;
    }
}

final class LedgerServiceProviderProbe extends LedgerServiceProvider
{
    public array $providerCalls  = [];
    public array $commandClasses = [];
    public LedgerScheduleSpy $schedule;

    public function __construct($app)
    {
        parent::__construct($app);
        $this->schedule = new LedgerScheduleSpy();
    }

    public function registerObservers(): void
    {
        $this->providerCalls[] = ['observers'];
    }

    public function registerExpansionsFrom($from = null, $namespace = null): void
    {
        $this->providerCalls[] = ['expansions', $from];
    }

    protected function loadRoutesFrom($path)
    {
        $this->providerCalls[] = ['routes', $path];
    }

    protected function loadMigrationsFrom($paths)
    {
        $this->providerCalls[] = ['migrations', $paths];
    }

    protected function loadViewsFrom($path, $namespace)
    {
        $this->providerCalls[] = ['views', $path, $namespace];
    }

    public function commands($commands)
    {
        $this->commandClasses = $commands;
    }

    public function scheduleCommands(?callable $callback = null): void
    {
        if ($callback) {
            $callback($this->schedule);
        }
    }
}

function ledgerProvider(): array
{
    $app    = Container::getInstance();
    $events = new LedgerEventDispatcherSpy();
    $app->instance('events', $events);
    Facade::clearResolvedInstance('events');

    return [new LedgerServiceProviderProbe($app), $app, $events];
}

test('Ledger service provider registers singleton accounting and gateway dependencies', function () {
    [$provider, $app] = ledgerProvider();

    $provider->register();

    expect($app->registeredProviders)->toContain(CoreServiceProvider::class)
        ->and($app->bound(LedgerService::class))->toBeTrue()
        ->and($app->bound(WalletService::class))->toBeTrue()
        ->and($app->bound(InvoiceService::class))->toBeTrue()
        ->and($app->bound(RevenueLifecycleService::class))->toBeTrue()
        ->and($app->bound(TalerRefundVerificationService::class))->toBeTrue()
        ->and($app->bound(PaymentGatewayManager::class))->toBeTrue()
        ->and($app->isAlias('ledger.gateway'))->toBeTrue()
        ->and($app->bound(PaymentService::class))->toBeTrue()
        ->and($app->make(PaymentGatewayManager::class))->toBeInstanceOf(PaymentGatewayManager::class)
        ->and($app->make(PaymentService::class))->toBeInstanceOf(PaymentService::class);
});

test('Ledger service provider boots package assets events template schema commands and schedule', function () {
    [$provider, $app, $events] = ledgerProvider();

    $provider->boot();

    expect($provider->providerCalls)->toContain(
        ['observers'],
        ['expansions', dirname(__DIR__, 2) . '/src/Providers/../Expansions'],
        ['routes', dirname(__DIR__, 2) . '/src/Providers/../routes.php'],
        ['migrations', dirname(__DIR__, 2) . '/src/Providers/../../migrations'],
        ['views', dirname(__DIR__, 2) . '/src/Providers/../../resources/views', 'ledger'],
    )
        ->and($events->listeners)->toBe([
            PaymentSucceeded::class => HandleSuccessfulPayment::class,
            PaymentFailed::class    => HandleFailedPayment::class,
            RefundProcessed::class  => HandleProcessedRefund::class,
        ])
        ->and($provider->commandClasses)->toBe([
            ProvisionLedgerDefaults::class,
            BackfillTransactionDirection::class,
            UpdateOverdueInvoices::class,
            RepairRevenueLifecycle::class,
            VerifyTalerSettlements::class,
            VerifyTalerRefunds::class,
            TalerSandboxE2E::class,
        ])
        ->and($provider->schedule->commands)->toBe(['ledger:taler:verify-refunds'])
        ->and($provider->schedule->task->calls)->toBe([
            ['everyFifteenMinutes'],
            ['name', 'ledger-taler-verify-refunds'],
            ['withoutOverlapping'],
        ]);

    $property = new ReflectionProperty(TemplateRenderService::class, 'contextTypes');
    $property->setAccessible(true);
    $contexts = $property->getValue();

    expect($contexts)->toHaveKey('invoice')
        ->and($contexts['invoice']['model'])->toBe(Fleetbase\Ledger\Models\Invoice::class)
        ->and($contexts['invoice']['variables'])->toHaveCount(30)
        ->and(array_column($contexts['invoice']['variables'], 'path'))
        ->toContain('invoice.number', 'transaction.reference', 'account.balance', 'wallet.formatted_balance');
});
