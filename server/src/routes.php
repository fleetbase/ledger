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
        | Example: POST /ledger/webhooks/stripe
        |          POST /ledger/webhooks/qpay
        */
        $router->post('webhooks/{driver}', 'WebhookController@handle');

        /*
        |--------------------------------------------------------------------------
        | Public API Routes (Authenticated via API Key — Customer / Driver facing)
        |--------------------------------------------------------------------------
        |
        | These routes are accessible to Fleetbase API consumers (customers, drivers)
        | authenticated via their API key. Each consumer can only access their own wallet.
        |
        | Prefix: /ledger/v1/...
        */
        $router->prefix(config('ledger.api.routing.version_prefix', 'v1'))->group(
            function ($router) {
                $router->group(
                    ['middleware' => ['fleetbase.api']],
                    function ($router) {
                        // ------------------------------------------------------------
                        // Wallet — Public (Customer / Driver)
                        // ------------------------------------------------------------
                        // GET  /ledger/v1/wallet              — Get own wallet (auto-provisions if needed)
                        // GET  /ledger/v1/wallet/balance      — Get own wallet balance
                        // GET  /ledger/v1/wallet/transactions — Get own wallet transaction history
                        // POST /ledger/v1/wallet/topup        — Top up own wallet via payment gateway
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
        | Internal Ledger API Routes
        |--------------------------------------------------------------------------
        |
        | These routes are consumed by the Fleetbase console frontend (Ember engine).
        | They are protected and require an authenticated session.
        */
        $router->prefix(config('ledger.api.routing.internal_prefix', 'int'))->group(
            function ($router) {
                $router->group(
                    ['prefix' => 'v1', 'middleware' => ['fleetbase.protected']],
                    function ($router) {
                        // ------------------------------------------------------------
                        // Accounts (Chart of Accounts)
                        // ------------------------------------------------------------
                        $router->get('accounts', 'Internal\v1\AccountController@query');
                        $router->get('accounts/{id}', 'Internal\v1\AccountController@find');
                        $router->post('accounts', 'Internal\v1\AccountController@create');
                        $router->put('accounts/{id}', 'Internal\v1\AccountController@update');
                        $router->delete('accounts/{id}', 'Internal\v1\AccountController@delete');
                        $router->post('accounts/{id}/recalculate-balance', 'Internal\v1\AccountController@recalculateBalance');

                        // General ledger for a specific account (all journal entries for that account)
                        $router->get('accounts/{id}/ledger', 'Internal\v1\AccountController@generalLedger');

                        // ------------------------------------------------------------
                        // Journal Entries
                        // ------------------------------------------------------------
                        $router->get('journals', 'Internal\v1\JournalController@query');
                        $router->get('journals/{id}', 'Internal\v1\JournalController@find');
                        $router->post('journals', 'Internal\v1\JournalController@create');
                        $router->delete('journals/{id}', 'Internal\v1\JournalController@delete');

                        // ------------------------------------------------------------
                        // Invoices
                        // ------------------------------------------------------------
                        $router->get('invoices', 'Internal\v1\InvoiceController@query');
                        $router->get('invoices/{id}', 'Internal\v1\InvoiceController@find');
                        $router->post('invoices', 'Internal\v1\InvoiceController@create');
                        $router->put('invoices/{id}', 'Internal\v1\InvoiceController@update');
                        $router->delete('invoices/{id}', 'Internal\v1\InvoiceController@delete');
                        $router->post('invoices/from-order', 'Internal\v1\InvoiceController@createFromOrder');
                        $router->post('invoices/{id}/record-payment', 'Internal\v1\InvoiceController@recordPayment');
                        $router->post('invoices/{id}/mark-as-sent', 'Internal\v1\InvoiceController@markAsSent');
                        $router->post('invoices/{id}/send', 'Internal\v1\InvoiceController@send');

                        // ------------------------------------------------------------
                        // Wallets — Internal (Operator / Admin)
                        // ------------------------------------------------------------
                        $router->get('wallets', 'Internal\v1\WalletController@query');
                        $router->get('wallets/{id}', 'Internal\v1\WalletController@find');
                        $router->post('wallets', 'Internal\v1\WalletController@create');
                        $router->put('wallets/{id}', 'Internal\v1\WalletController@update');
                        $router->delete('wallets/{id}', 'Internal\v1\WalletController@delete');

                        // Balance operations
                        $router->post('wallets/{id}/deposit', 'Internal\v1\WalletController@deposit');
                        $router->post('wallets/{id}/withdraw', 'Internal\v1\WalletController@withdraw');
                        $router->post('wallets/transfer', 'Internal\v1\WalletController@transfer');
                        $router->post('wallets/{id}/topup', 'Internal\v1\WalletController@topUp');
                        $router->post('wallets/{id}/payout', 'Internal\v1\WalletController@payout');

                        // State management
                        $router->post('wallets/{id}/freeze', 'Internal\v1\WalletController@freeze');
                        $router->post('wallets/{id}/unfreeze', 'Internal\v1\WalletController@unfreeze');
                        $router->post('wallets/{id}/recalculate', 'Internal\v1\WalletController@recalculate');

                        // Transaction history for a specific wallet
                        $router->get('wallets/{id}/transactions', 'Internal\v1\WalletController@getTransactions');

                        // ------------------------------------------------------------
                        // Wallet Transactions — Internal (standalone query endpoint)
                        // ------------------------------------------------------------
                        $router->get('wallet-transactions', 'Internal\v1\WalletTransactionController@query');
                        $router->get('wallet-transactions/{id}', 'Internal\v1\WalletTransactionController@find');

                        // ------------------------------------------------------------
                        // Transactions (read-only view of core-api Transaction records)
                        // ------------------------------------------------------------
                        $router->get('transactions', 'Internal\v1\TransactionController@query');
                        $router->get('transactions/{id}', 'Internal\v1\TransactionController@find');

                        // ------------------------------------------------------------
                        // Payment Gateways
                        // ------------------------------------------------------------
                        // Driver manifest — returns all available drivers with config schemas
                        // Must be registered BEFORE the {id} route to avoid route conflicts
                        $router->get('gateways/drivers', 'Internal\v1\GatewayController@drivers');

                        // Gateway CRUD
                        $router->get('gateways', 'Internal\v1\GatewayController@index');
                        $router->post('gateways', 'Internal\v1\GatewayController@store');
                        $router->get('gateways/{id}', 'Internal\v1\GatewayController@show');
                        $router->put('gateways/{id}', 'Internal\v1\GatewayController@update');
                        $router->delete('gateways/{id}', 'Internal\v1\GatewayController@destroy');

                        // Gateway payment operations
                        $router->post('gateways/{id}/charge', 'Internal\v1\GatewayController@charge');
                        $router->post('gateways/{id}/refund', 'Internal\v1\GatewayController@refund');
                        $router->post('gateways/{id}/setup-intent', 'Internal\v1\GatewayController@setupIntent');

                        // Gateway transaction history
                        $router->get('gateways/{id}/transactions', 'Internal\v1\GatewayController@transactions');

                        // ------------------------------------------------------------
                        // Reports & Financial Statements
                        // ------------------------------------------------------------
                        // Dashboard — KPIs, revenue trend, recent journals, invoice counts
                        $router->get('reports/dashboard', 'Internal\v1\ReportController@dashboard');

                        // Trial Balance — all accounts with debit/credit totals as of a date
                        $router->get('reports/trial-balance', 'Internal\v1\ReportController@trialBalance');

                        // Balance Sheet — Assets = Liabilities + Equity as of a date
                        $router->get('reports/balance-sheet', 'Internal\v1\ReportController@balanceSheet');

                        // Income Statement (P&L) — Revenue - Expenses = Net Income for a period
                        $router->get('reports/income-statement', 'Internal\v1\ReportController@incomeStatement');

                        // Cash Flow Summary — Operating / Financing / Investing activities for a period
                        $router->get('reports/cash-flow', 'Internal\v1\ReportController@cashFlow');

                        // AR Aging — Outstanding invoices bucketed by days overdue
                        $router->get('reports/ar-aging', 'Internal\v1\ReportController@arAging');

                        // Wallet Summary — wallet counts, balances, period stats, top wallets
                        $router->get('reports/wallet-summary', 'Internal\v1\ReportController@walletSummary');
                    }
                );
            }
        );
    }
);
