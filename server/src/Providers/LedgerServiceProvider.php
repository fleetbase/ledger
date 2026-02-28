<?php

namespace Fleetbase\Ledger\Providers;

use Fleetbase\Ledger\Events\PaymentFailed;
use Fleetbase\Ledger\Events\PaymentSucceeded;
use Fleetbase\Ledger\Events\RefundProcessed;
use Fleetbase\Ledger\Listeners\HandleFailedPayment;
use Fleetbase\Ledger\Listeners\HandleProcessedRefund;
use Fleetbase\Ledger\Listeners\HandleSuccessfulPayment;
use Fleetbase\Ledger\PaymentGatewayManager;
use Fleetbase\Ledger\Services\InvoiceService;
use Fleetbase\Ledger\Services\LedgerService;
use Fleetbase\Ledger\Services\PaymentService;
use Fleetbase\Ledger\Services\WalletService;
use Fleetbase\Providers\CoreServiceProvider;
use Illuminate\Support\Facades\Event;

if (!class_exists(CoreServiceProvider::class)) {
    throw new \Exception('Ledger cannot be loaded without `fleetbase/core-api` installed!');
}

/**
 * LedgerServiceProvider
 *
 * Registers all Ledger services, the payment gateway manager,
 * event-listener bindings, and bootstraps routes and migrations.
 */
class LedgerServiceProvider extends CoreServiceProvider
{
    /**
     * The observers registered with the service provider.
     *
     * @var array
     */
    public $observers = [
        \Fleetbase\Ledger\Models\Invoice::class => \Fleetbase\Ledger\Observers\InvoiceObserver::class,
    ];

    /**
     * Register any application services.
     *
     * Within the register method, you should only bind things into the
     * service container. You should never attempt to register any event
     * listeners, routes, or any other piece of functionality within the
     * register method.
     *
     * More information on this can be found in the Laravel documentation:
     * https://laravel.com/docs/8.x/providers
     *
     * @return void
     */
    public function register()
    {
        $this->app->register(CoreServiceProvider::class);

        // Core accounting services
        $this->app->singleton(LedgerService::class);
        $this->app->singleton(WalletService::class);
        $this->app->singleton(InvoiceService::class);

        // Payment gateway system
        // The PaymentGatewayManager is bound as a singleton and also aliased
        // as 'ledger.gateway' for convenient facade-style access.
        $this->app->singleton(PaymentGatewayManager::class, function ($app) {
            return new PaymentGatewayManager($app);
        });

        $this->app->alias(PaymentGatewayManager::class, 'ledger.gateway');

        // PaymentService depends on PaymentGatewayManager
        $this->app->singleton(PaymentService::class, function ($app) {
            return new PaymentService($app->make(PaymentGatewayManager::class));
        });
    }

    /**
     * Bootstrap any package services.
     *
     * @return void
     *
     * @throws \Exception if the `fleetbase/core-api` package is not installed
     */
    public function boot()
    {
        $this->registerObservers();
        $this->registerExpansionsFrom(__DIR__ . '/../Expansions');
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');
        $this->loadMigrationsFrom(__DIR__ . '/../../migrations');

        // Register event-listener bindings for the payment gateway system
        $this->registerPaymentEvents();
    }

    /**
     * Register all payment-related event-listener pairs.
     *
     * All listeners implement ShouldQueue and will be processed
     * asynchronously by the queue worker.
     *
     * @return void
     */
    private function registerPaymentEvents(): void
    {
        Event::listen(PaymentSucceeded::class, HandleSuccessfulPayment::class);
        Event::listen(PaymentFailed::class,    HandleFailedPayment::class);
        Event::listen(RefundProcessed::class,  HandleProcessedRefund::class);
    }
}
