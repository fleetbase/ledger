<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::prefix(config('ledger.api.routing.prefix', 'ledger'))->namespace('Fleetbase\Ledger\Http\Controllers')->group(
    function ($router) {
        /*
        |--------------------------------------------------------------------------
        | Internal Billing API Routes
        |--------------------------------------------------------------------------
        |
        | Primary internal routes for console.
        */
        $router->prefix(config('ledger.api.routing.internal_prefix', 'int'))->group(
            function ($router) {
                $router->group(
                    ['prefix' => 'v1', 'middleware' => ['fleetbase.protected']],
                    function ($router) {
                        // Accounts
                        $router->get('accounts', 'Internal\v1\AccountController@query');
                        $router->get('accounts/{id}', 'Internal\v1\AccountController@find');
                        $router->post('accounts', 'Internal\v1\AccountController@create');
                        $router->put('accounts/{id}', 'Internal\v1\AccountController@update');
                        $router->delete('accounts/{id}', 'Internal\v1\AccountController@delete');
                        $router->post('accounts/{id}/recalculate-balance', 'Internal\v1\AccountController@recalculateBalance');

                        // Invoices
                        $router->get('invoices', 'Internal\v1\InvoiceController@query');
                        $router->get('invoices/{id}', 'Internal\v1\InvoiceController@find');
                        $router->post('invoices', 'Internal\v1\InvoiceController@create');
                        $router->put('invoices/{id}', 'Internal\v1\InvoiceController@update');
                        $router->delete('invoices/{id}', 'Internal\v1\InvoiceController@delete');
                        $router->post('invoices/from-order', 'Internal\v1\InvoiceController@createFromOrder');
                        $router->post('invoices/{id}/record-payment', 'Internal\v1\InvoiceController@recordPayment');
                        $router->post('invoices/{id}/mark-as-sent', 'Internal\v1\InvoiceController@markAsSent');

                        // Wallets
                        $router->get('wallets', 'Internal\v1\WalletController@query');
                        $router->get('wallets/{id}', 'Internal\v1\WalletController@find');
                        $router->post('wallets', 'Internal\v1\WalletController@create');
                        $router->put('wallets/{id}', 'Internal\v1\WalletController@update');
                        $router->delete('wallets/{id}', 'Internal\v1\WalletController@delete');
                        $router->post('wallets/{id}/deposit', 'Internal\v1\WalletController@deposit');
                        $router->post('wallets/{id}/withdraw', 'Internal\v1\WalletController@withdraw');
                        $router->post('wallets/transfer', 'Internal\v1\WalletController@transfer');

                        // Transactions (System-wide viewer)
                        $router->get('transactions', 'Internal\v1\TransactionController@query');
                        $router->get('transactions/{id}', 'Internal\v1\TransactionController@find');
                    }
                );
            }
        );
    }
);
