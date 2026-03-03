<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Ledger API Routes
|--------------------------------------------------------------------------
|
| All routes are prefixed with the configured ledger prefix (default: 'ledger')
| and are protected by the 'fleetbase.protected' middleware which requires a
| valid Fleetbase session or API key.
|
*/
Route::prefix(config('ledger.api.routing.prefix', 'ledger'))->namespace('Fleetbase\Ledger\Http\Controllers')->group(
    function ($router) {
        /*
        |--------------------------------------------------------------------------
        | Webhook Routes (Public — No Auth Required)
        |--------------------------------------------------------------------------
        |
        | These routes receive inbound webhook callbacks from payment gateways.
        | They must be publicly accessible (no auth middleware) but each driver
        | performs its own signature verification internally.
        |
        | Route: POST /ledger/webhooks/{driver}
        */
        $router->post('webhooks/{driver}', 'WebhookController@handle');

        /*
        |--------------------------------------------------------------------------
        | Public API Routes (Authenticated via API Key — Customer / Driver facing)
        |--------------------------------------------------------------------------
        */
        $router->prefix(config('ledger.api.routing.version_prefix', 'v1'))->group(
            function ($router) {
                $router->group(
                    ['middleware' => ['fleetbase.api']],
                    function ($router) {
                        $router->get('wallet', 'Api\v1\WalletApiController@getWallet');
                        $router->get('wallet/balance', 'Api\v1\WalletApiController@getBalance');
                        $router->get('wallet/transactions', 'Api\v1\WalletApiController@getTransactions');
                        $router->post('wallet/topup', 'Api\v1\WalletApiController@topUp');
                    }
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Internal Routes (Authenticated via Fleetbase Session — Console)
        |--------------------------------------------------------------------------
        */
        $router->prefix(config('ledger.api.routing.internal_prefix', 'int'))->namespace('Internal')->group(
            function ($router) {
                $router->prefix(config('ledger.api.routing.version_prefix', 'v1'))->namespace('v1')->group(
                    function ($router) {
                        $router->group(
                            ['middleware' => ['fleetbase.protected']],
                            function ($router) {
                                // ----------------------------------------------------------------
                                // Chart of Accounts
                                // ----------------------------------------------------------------
                                $router->fleetbaseRoutes(
                                    'accounts',
                                    function ($router, $controller) {
                                        $router->get('{id}/general-ledger', $controller('generalLedger'));
                                        $router->post('{id}/recalculate-balance', $controller('recalculateBalance'));
                                    }
                                );

                                // ----------------------------------------------------------------
                                // Invoices
                                // ----------------------------------------------------------------
                                $router->fleetbaseRoutes(
                                    'invoices',
                                    function ($router, $controller) {
                                        $router->post('create-from-order', $controller('createFromOrder'));
                                        $router->post('{id}/record-payment', $controller('recordPayment'));
                                        $router->post('{id}/mark-as-sent', $controller('markAsSent'));
                                        $router->post('{id}/send', $controller('send'));
                                    }
                                );

                                // ----------------------------------------------------------------
                                // Journal Entries
                                // ----------------------------------------------------------------
                                $router->fleetbaseRoutes(
                                    'journals',
                                    function ($router, $controller) {
                                        $router->post('manual', $controller('createManual'));
                                    }
                                );

                                // ----------------------------------------------------------------
                                // Wallets
                                // ----------------------------------------------------------------
                                $router->fleetbaseRoutes(
                                    'wallets',
                                    function ($router, $controller) {
                                        $router->post('{id}/transfer', $controller('transfer'));
                                        $router->post('{id}/credit', $controller('credit'));
                                        $router->post('{id}/topup', $controller('topUp'));
                                        $router->post('{id}/payout', $controller('payout'));
                                        $router->post('{id}/freeze', $controller('freeze'));
                                        $router->post('{id}/unfreeze', $controller('unfreeze'));
                                        $router->post('{id}/recalculate', $controller('recalculate'));
                                        $router->get('{id}/transactions', $controller('getTransactions'));
                                    }
                                );

                                // ----------------------------------------------------------------
                                // Transactions (core-api Transaction records — read-only)
                                // ----------------------------------------------------------------
                                $router->fleetbaseRoutes('transactions');

                                // ----------------------------------------------------------------
                                // Payment Gateways
                                // ----------------------------------------------------------------
                                // The 'drivers' static route must be registered BEFORE fleetbaseRoutes
                                // to avoid being swallowed by the /{id} find route.
                                $router->get('gateways/drivers', 'GatewayController@drivers');

                                $router->fleetbaseRoutes(
                                    'gateways',
                                    function ($router, $controller) {
                                        $router->post('{id}/charge', $controller('charge'));
                                        $router->post('{id}/refund', $controller('refund'));
                                        $router->post('{id}/setup-intent', $controller('setupIntent'));
                                        $router->get('{id}/transactions', $controller('transactions'));
                                    }
                                );

                                // ----------------------------------------------------------------
                                // Reports & Financial Statements
                                // ----------------------------------------------------------------
                                $router->get('reports/dashboard', 'ReportController@dashboard');
                                $router->get('reports/trial-balance', 'ReportController@trialBalance');
                                $router->get('reports/balance-sheet', 'ReportController@balanceSheet');
                                $router->get('reports/income-statement', 'ReportController@incomeStatement');
                                $router->get('reports/cash-flow', 'ReportController@cashFlow');
                                $router->get('reports/ar-aging', 'ReportController@arAging');
                                $router->get('reports/wallet-summary', 'ReportController@walletSummary');
                            }
                        );
                    }
                );
            }
        );
    }
);
