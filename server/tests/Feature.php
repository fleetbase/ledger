<?php

test('example', function () {
    expect(true)->toBeTrue();
});

test('ledger dashboard report endpoints are registered', function () {
    $routes = file_get_contents(__DIR__ . '/../src/routes.php');

    expect($routes)
        ->toContain('reports/dashboard/summary')
        ->toContain('reports/dashboard/revenue-trend')
        ->toContain('reports/dashboard/cash-flow-summary')
        ->toContain('reports/dashboard/invoice-status')
        ->toContain('reports/dashboard/ar-aging-summary')
        ->toContain('reports/dashboard/wallet-balances')
        ->toContain('reports/dashboard/activity');
});

test('ledger navigator search endpoint is registered', function () {
    $routes     = file_get_contents(__DIR__ . '/../src/routes.php');
    $controller = file_get_contents(__DIR__ . '/../src/Http/Controllers/Internal/v1/SearchController.php');

    expect($routes)
        ->toContain("\$router->get('search', 'SearchController@search')");

    expect($controller)
        ->toContain("private const SEARCH_TYPES = ['invoices', 'templates', 'wallets', 'transactions', 'gateways', 'accounts', 'journals']")
        ->toContain("return response()->json(['results' => []]);")
        ->toContain("'invoices'     => 'ledger see invoice'")
        ->toContain("'templates'    => 'ledger see invoice-template'")
        ->toContain("'transactions' => 'ledger see transaction'")
        ->toContain("'route'       => 'console.ledger.billing.invoices.index.details'")
        ->toContain("'route'       => 'console.ledger.payments.wallets.index.details'")
        ->toContain("'route'       => 'console.ledger.accounting.journal.index.details'")
        ->toContain("'models'      => [\$invoice->uuid]")
        ->toContain("'models'      => [\$template->uuid]")
        ->toContain("'models'      => [\$wallet->uuid]")
        ->toContain("'models'      => [\$transaction->uuid]")
        ->toContain("'models'      => [\$gateway->uuid]")
        ->toContain("'models'      => [\$account->uuid]")
        ->toContain("'models'      => [\$journal->uuid]");
});

test('order accounting observer preserves seed metadata on storefront sale journal entries', function () {
    $observer = file_get_contents(__DIR__ . '/../src/Observers/OrderAccountingObserver.php');

    expect($observer)
        ->toContain("foreach (['seed', 'seed_id'] as \$seedMetaKey)")
        ->toContain('$meta[$seedMetaKey] = $order->getMeta($seedMetaKey)')
        ->toContain("'meta'         => \$meta");
});

test('order accounting observer handles cancellation and reinstatement journal corrections', function () {
    $observer = file_get_contents(__DIR__ . '/../src/Observers/OrderAccountingObserver.php');
    $service  = file_get_contents(__DIR__ . '/../src/Services/RevenueLifecycleService.php');
    $provider = file_get_contents(__DIR__ . '/../src/Providers/LedgerServiceProvider.php');

    expect($provider)
        ->toContain('Fleetbase\\\\FleetOps\\\\Models\\\\Order')
        ->toContain('OrderAccountingObserver::class');

    expect($observer)
        ->toContain('RevenueLifecycleService')
        ->toContain('handleOrderCanceled')
        ->toContain('handleOrderRestored')
        ->toContain('handleOrderDeleted')
        ->toContain('handleOrderRestoredFromDelete');

    expect($service)
        ->toContain('storefront_sale_reversal')
        ->toContain('storefront_sale_reinstatement')
        ->toContain('revenue_recognition_reversal')
        ->toContain('revenue_recognition_reinstatement')
        ->toContain('reverses_journal_uuid')
        ->toContain('reinstates_journal_uuid')
        ->toContain('revenue_lifecycle_previous_status');
});

test('invoice deletion and repair command use the revenue lifecycle service', function () {
    $invoiceObserver = file_get_contents(__DIR__ . '/../src/Observers/InvoiceObserver.php');
    $provider        = file_get_contents(__DIR__ . '/../src/Providers/LedgerServiceProvider.php');
    $command         = file_get_contents(__DIR__ . '/../src/Console/Commands/RepairRevenueLifecycle.php');

    expect($invoiceObserver)
        ->toContain('RevenueLifecycleService')
        ->toContain('handleInvoiceDeleting')
        ->toContain('handleInvoiceRestored')
        ->toContain('isForceDeleting');

    expect($provider)
        ->toContain('RevenueLifecycleService::class')
        ->toContain('RepairRevenueLifecycle::class');

    expect($command)
        ->toContain('fleetops:repair-revenue-lifecycle')
        ->toContain('{--apply')
        ->toContain('repairOrder')
        ->toContain('repairInvoice');
});

test('profit and loss deduplication preserves reversal and reinstatement journals', function () {
    $service = file_get_contents(__DIR__ . '/../src/Services/LedgerService.php');

    expect($service)
        ->toContain("str_ends_with((string) \$journal->type, '_reversal')")
        ->toContain('journal-reversal')
        ->toContain('reverses_journal_uuid')
        ->toContain("str_ends_with((string) \$journal->type, '_reinstatement')")
        ->toContain('journal-reinstatement')
        ->toContain('reinstates_journal_uuid');
});
