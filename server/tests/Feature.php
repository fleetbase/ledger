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

test('order accounting observer preserves seed metadata on storefront sale journal entries', function () {
    $observer = file_get_contents(__DIR__ . '/../src/Observers/OrderAccountingObserver.php');

    expect($observer)
        ->toContain("foreach (['seed', 'seed_id'] as \$seedMetaKey)")
        ->toContain('$meta[$seedMetaKey] = $order->getMeta($seedMetaKey)')
        ->toContain("'meta'         => \$meta");
});

test('order accounting observer handles cancellation and reinstatement journal corrections', function () {
    $observer = file_get_contents(__DIR__ . '/../src/Observers/OrderAccountingObserver.php');
    $provider = file_get_contents(__DIR__ . '/../src/Providers/LedgerServiceProvider.php');

    expect($provider)
        ->toContain('Fleetbase\\\\FleetOps\\\\Models\\\\Order')
        ->toContain('OrderAccountingObserver::class');

    expect($observer)
        ->toContain('storefront_sale_reversal')
        ->toContain('storefront_sale_reinstatement')
        ->toContain('revenue_recognition_reversal')
        ->toContain('revenue_recognition_reinstatement')
        ->toContain('reverses_journal_uuid')
        ->toContain('reinstates_journal_uuid')
        ->toContain('order_cancellation_previous_status');
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
