<?php

use Fleetbase\TestSupport\RouteRegistrar;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;

test('Ledger registers public API internal resource action settings search and report routes', function () {
    RouteRegistrar::reset();
    Facade::clearResolvedInstance('router');
    Container::getInstance()->instance('router', new RouteRegistrar());

    require __DIR__ . '/../../src/routes.php';

    $routes     = RouteRegistrar::$routes;
    $signatures = array_map(
        fn (array $route): string => implode('|', [
            $route[0],
            $route[1],
            is_string($route[2]) ? $route[2] : '',
        ]),
        $routes
    );

    expect($routes)->toHaveCount(70)
        ->and($signatures)->toContain(
            'POST|webhooks/{driver}|WebhookController@handle',
            'GET|invoices/{public_id}|PublicInvoiceController@show',
            'POST|wallet/topup|Api\v1\WalletApiController@topUp',
            'RESOURCE|accounts|',
            'POST|{id}/record-payment|invoicesController@recordPayment',
            'POST|{id}/transfer|walletsController@transfer',
            'GET|gateways/drivers|GatewayController@drivers',
            'POST|{id}/register-webhook|gatewaysController@registerWebhook',
            'POST|accounting-settings|SettingController@saveAccountingSettings',
            'GET|search|SearchController@search',
            'GET|reports/income-statement|ReportController@incomeStatement',
            'GET|reports/wallet-summary|ReportController@walletSummary',
        );
});

test('Ledger logs requests to its public API and customer invoice routes but not webhooks or internal routes', function () {
    RouteRegistrar::reset();
    Facade::clearResolvedInstance('router');
    Container::getInstance()->instance('router', new RouteRegistrar());

    require __DIR__ . '/../../src/routes.php';

    $middleware = [];
    foreach (RouteRegistrar::$routes as $route) {
        if (is_string($route[2])) {
            $middleware[$route[0] . '|' . $route[1] . '|' . $route[2]] = $route[3] ?? [];
        }
    }

    // The public API's fleetbase.api group runs LogApiRequests
    expect($middleware['POST|wallet/topup|Api\v1\WalletApiController@topUp'])->toBe(['fleetbase.api'])
        ->and($middleware['POST|invoices/{public_id}/pay|PublicInvoiceController@pay'])->toBe([Fleetbase\Http\Middleware\LogApiRequests::class])
        ->and($middleware['GET|invoices/{public_id}|PublicInvoiceController@show'])->toBe([Fleetbase\Http\Middleware\LogApiRequests::class])
        ->and($middleware['POST|webhooks/{driver}|WebhookController@handle'])->toBe([])
        ->and($middleware['GET|gateways/drivers|GatewayController@drivers'])->toBe(['fleetbase.protected']);
});
