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
                        // Wallets
                        // ------------------------------------------------------------
                        $router->get('wallets', 'Internal\v1\WalletController@query');
                        $router->get('wallets/{id}', 'Internal\v1\WalletController@find');
                        $router->post('wallets', 'Internal\v1\WalletController@create');
                        $router->put('wallets/{id}', 'Internal\v1\WalletController@update');
                        $router->delete('wallets/{id}', 'Internal\v1\WalletController@delete');
                        $router->post('wallets/{id}/deposit', 'Internal\v1\WalletController@deposit');
                        $router->post('wallets/{id}/withdraw', 'Internal\v1\WalletController@withdraw');
                        $router->post('wallets/transfer', 'Internal\v1\WalletController@transfer');

                        // ------------------------------------------------------------
                        // Transactions (read-only view of core-api Transaction records)
                        // ------------------------------------------------------------
                        $router->get('transactions', 'Internal\v1\TransactionController@query');
                        $router->get('transactions/{id}', 'Internal\v1\TransactionController@find');

                        // ------------------------------------------------------------
                        // Reports & Financial Statements
                        // ------------------------------------------------------------
                        // Trial balance — debit/credit totals across all accounts
                        $router->get('reports/trial-balance', 'Internal\v1\ReportController@trialBalance');
                    }
                );
            }
        );
    }
);
