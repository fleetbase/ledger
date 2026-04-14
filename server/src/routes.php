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
        | Public Customer Invoice Routes (No Auth Required)
        |--------------------------------------------------------------------------
        |
        | These routes allow customers to view and pay invoices without logging in.
        | They are scoped by the globally-unique invoice public_id / uuid.
        |
        | GET  /ledger/public/invoices/{public_id}
        | GET  /ledger/public/invoices/{public_id}/gateways
        | POST /ledger/public/invoices/{public_id}/pay
        */
        $router->prefix('public')->namespace('Public')->group(function ($router) {
            $router->get('invoices/{public_id}', 'PublicInvoiceController@show');
            $router->get('invoices/{public_id}/gateways', 'PublicInvoiceController@gateways');
            $router->post('invoices/{public_id}/pay', 'PublicInvoiceController@pay');
        });

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
                                        $router->post('{id}/preview', $controller('preview'));
                                        $router->post('{id}/render-pdf', $controller('renderPdf'));
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
                                // Settings (Invoice / Payment / Accounting)
                                // ----------------------------------------------------------------
                                $router->prefix('settings')->group(function ($router) {
                                    // Invoice settings
                                    $router->get('invoice-settings', 'SettingController@getInvoiceSettings');
                                    $router->post('invoice-settings', 'SettingController@saveInvoiceSettings');

                                    // Payment settings
                                    $router->get('payment-settings', 'SettingController@getPaymentSettings');
                                    $router->post('payment-settings', 'SettingController@savePaymentSettings');

                                    // Accounting settings
                                    $router->get('accounting-settings', 'SettingController@getAccountingSettings');
                                    $router->post('accounting-settings', 'SettingController@saveAccountingSettings');
                                });

                                // ----------------------------------------------------------------
                                // Reports & Financial Statements
                                // ----------------------------------------------------------------
                                $router->get('reports/general-ledger', 'ReportController@generalLedger');
                                $router->get('reports/dashboard', 'ReportController@dashboard');
                                $router->get('reports/trial-balance', 'ReportController@trialBalance');
                                $router->get('reports/balance-sheet', 'ReportController@balanceSheet');
                                $router->get('reports/income-statement', 'ReportController@incomeStatement');
                                $router->get('reports/cash-flow', 'ReportController@cashFlow');
                                $router->get('reports/ar-aging', 'ReportController@arAging');
                                $router->get('reports/wallet-summary', 'ReportController@walletSummary');

                                // ----------------------------------------------------------------
                                // GL Assignment Rules
                                // ----------------------------------------------------------------
                                $router->group(['prefix' => 'gl-assignment-rules'], function () use ($router) {
                                    $router->get('/', 'GlAssignmentRuleController@queryRecord');
                                    $router->get('{id}', 'GlAssignmentRuleController@findRecord');
                                    $router->post('/', 'GlAssignmentRuleController@createRecord');
                                    $router->put('{id}', 'GlAssignmentRuleController@updateRecord');
                                    $router->delete('{id}', 'GlAssignmentRuleController@deleteRecord');
                                });

                                // ----------------------------------------------------------------
                                // GL Assignments
                                // ----------------------------------------------------------------
                                $router->group(['prefix' => 'gl-assignments'], function () use ($router) {
                                    $router->get('/', 'GlAssignmentController@queryRecord');
                                    $router->post('/', 'GlAssignmentController@createRecord');
                                    $router->delete('{id}', 'GlAssignmentController@deleteRecord');
                                });

                                // ----------------------------------------------------------------
                                // GL Exports
                                // ----------------------------------------------------------------
                                $router->group(['prefix' => 'gl-exports'], function () use ($router) {
                                    $router->get('/', 'GlExportBatchController@queryRecord');
                                    $router->post('generate', 'GlExportBatchController@generate');
                                    $router->get('{id}/download', 'GlExportBatchController@download');
                                });

                                // ----------------------------------------------------------------
                                // Carrier Invoices
                                // ----------------------------------------------------------------
                                $router->group(['prefix' => 'carrier-invoices'], function () use ($router) {
                                    $router->get('/', 'CarrierInvoiceController@queryRecord');
                                    $router->get('{id}', 'CarrierInvoiceController@findRecord');
                                    $router->post('/', 'CarrierInvoiceController@createRecord');
                                    $router->put('{id}', 'CarrierInvoiceController@updateRecord');
                                    $router->delete('{id}', 'CarrierInvoiceController@deleteRecord');
                                    $router->post('{id}/audit', 'CarrierInvoiceController@audit');
                                    $router->post('{id}/resolve', 'CarrierInvoiceController@resolve');
                                    $router->post('batch-approve', 'CarrierInvoiceController@batchApprove');
                                });

                                // ----------------------------------------------------------------
                                // Carrier Invoice Audit Rules
                                // ----------------------------------------------------------------
                                $router->group(['prefix' => 'carrier-invoice-audit-rules'], function () use ($router) {
                                    $router->get('/', 'CarrierInvoiceAuditRuleController@queryRecord');
                                    $router->get('{id}', 'CarrierInvoiceAuditRuleController@findRecord');
                                    $router->post('/', 'CarrierInvoiceAuditRuleController@createRecord');
                                    $router->put('{id}', 'CarrierInvoiceAuditRuleController@updateRecord');
                                    $router->delete('{id}', 'CarrierInvoiceAuditRuleController@deleteRecord');
                                });

                                // ----------------------------------------------------------------
                                // Service Agreements
                                // ----------------------------------------------------------------
                                $router->group(['prefix' => 'service-agreements'], function () use ($router) {
                                    $router->get('/', 'ServiceAgreementController@queryRecord');
                                    $router->get('{id}', 'ServiceAgreementController@findRecord');
                                    $router->post('/', 'ServiceAgreementController@createRecord');
                                    $router->put('{id}', 'ServiceAgreementController@updateRecord');
                                    $router->delete('{id}', 'ServiceAgreementController@deleteRecord');
                                });

                                // ----------------------------------------------------------------
                                // Charge Templates
                                // ----------------------------------------------------------------
                                $router->group(['prefix' => 'charge-templates'], function () use ($router) {
                                    $router->get('/', 'ChargeTemplateController@queryRecord');
                                    $router->get('{id}', 'ChargeTemplateController@findRecord');
                                    $router->post('/', 'ChargeTemplateController@createRecord');
                                    $router->put('{id}', 'ChargeTemplateController@updateRecord');
                                    $router->delete('{id}', 'ChargeTemplateController@deleteRecord');
                                });

                                // ----------------------------------------------------------------
                                // Client Invoices
                                // ----------------------------------------------------------------
                                $router->group(['prefix' => 'client-invoices'], function () use ($router) {
                                    $router->get('/', 'ClientInvoiceController@queryRecord');
                                    $router->get('{id}', 'ClientInvoiceController@findRecord');
                                    $router->post('/', 'ClientInvoiceController@createRecord');
                                    $router->put('{id}', 'ClientInvoiceController@updateRecord');
                                    $router->delete('{id}', 'ClientInvoiceController@deleteRecord');
                                    $router->post('generate', 'ClientInvoiceController@generate');
                                    $router->post('batch-generate', 'ClientInvoiceController@batchGenerate');
                                });

                                // ----------------------------------------------------------------
                                // Cost Benchmarks
                                // ----------------------------------------------------------------
                                $router->group(['prefix' => 'cost-benchmarks'], function () use ($router) {
                                    $router->get('/', 'CostBenchmarkController@queryRecord');
                                    $router->get('{id}', 'CostBenchmarkController@findRecord');
                                    $router->post('/', 'CostBenchmarkController@createRecord');
                                    $router->put('{id}', 'CostBenchmarkController@updateRecord');
                                    $router->delete('{id}', 'CostBenchmarkController@deleteRecord');
                                });

                                // ----------------------------------------------------------------
                                // Gainshare Rules
                                // ----------------------------------------------------------------
                                $router->group(['prefix' => 'gainshare-rules'], function () use ($router) {
                                    $router->get('/', 'GainshareRuleController@queryRecord');
                                    $router->get('{id}', 'GainshareRuleController@findRecord');
                                    $router->post('/', 'GainshareRuleController@createRecord');
                                    $router->put('{id}', 'GainshareRuleController@updateRecord');
                                    $router->delete('{id}', 'GainshareRuleController@deleteRecord');
                                });

                                // ----------------------------------------------------------------
                                // Gainshare Executions
                                // ----------------------------------------------------------------
                                $router->group(['prefix' => 'gainshare'], function () use ($router) {
                                    $router->get('/', 'GainshareController@queryRecord');
                                    $router->get('summary', 'GainshareController@summary');
                                    $router->get('{id}', 'GainshareController@findRecord');
                                });

                                // ----------------------------------------------------------------
                                // Pay Files
                                // ----------------------------------------------------------------
                                $router->group(['prefix' => 'pay-files'], function () use ($router) {
                                    $router->get('/', 'PayFileController@queryRecord');
                                    $router->get('{id}', 'PayFileController@findRecord');
                                    $router->post('/', 'PayFileController@createRecord');
                                    $router->put('{id}', 'PayFileController@updateRecord');
                                    $router->delete('{id}', 'PayFileController@deleteRecord');
                                    $router->post('generate', 'PayFileController@generate');
                                    $router->get('{id}/download', 'PayFileController@download');
                                    $router->post('{id}/mark-sent', 'PayFileController@markSent');
                                    $router->post('{id}/mark-confirmed', 'PayFileController@markConfirmed');
                                    $router->post('{id}/cancel', 'PayFileController@cancel');
                                });

                                // ----------------------------------------------------------------
                                // Pay File Schedules
                                // ----------------------------------------------------------------
                                $router->group(['prefix' => 'pay-file-schedules'], function () use ($router) {
                                    $router->get('/', 'PayFileScheduleController@queryRecord');
                                    $router->get('{id}', 'PayFileScheduleController@findRecord');
                                    $router->post('/', 'PayFileScheduleController@createRecord');
                                    $router->put('{id}', 'PayFileScheduleController@updateRecord');
                                    $router->delete('{id}', 'PayFileScheduleController@deleteRecord');
                                    $router->post('{id}/run-now', 'PayFileScheduleController@runNow');
                                });
                            }
                        );
                    }
                );
            }
        );
    }
);
