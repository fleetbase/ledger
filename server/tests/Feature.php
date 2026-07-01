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

test('invoice send validation failures return json errors', function () {
    $controller = file_get_contents(__DIR__ . '/../src/Http/Controllers/Internal/v1/InvoiceController.php');

    expect($controller)
        ->not->toContain('abort(422, $e->getMessage())')
        ->toContain("return response()->json(['error' => \$e->getMessage()], 422);");
});

test('invoice settings normalize payment terms and legacy due date offset', function () {
    $controller = file_get_contents(__DIR__ . '/../src/Http/Controllers/Internal/v1/SettingController.php');

    expect($controller)
        ->toContain('private function normalizeInvoiceSettings')
        ->toContain("if (array_key_exists('payment_terms_days', \$settings))")
        ->toContain("} elseif (array_key_exists('due_date_offset_days', \$settings))")
        ->toContain("\$terms = \$defaults['payment_terms_days'] ?? \$defaults['due_date_offset_days'] ?? 30;")
        ->toContain('$settings = array_merge($defaults, $settings);')
        ->toContain("\$settings['payment_terms_days']   = \$terms;")
        ->toContain("\$settings['due_date_offset_days'] = \$terms;")
        ->toContain("\$settings = \$this->normalizeInvoiceSettings(Setting::lookupCompany('ledger.invoice-settings', \$defaults), \$defaults);")
        ->toContain('private function syncInvoicePaymentTerms(array $settings): array')
        ->toContain("\$invoiceSettings = \$this->syncInvoicePaymentTerms(\$request->input('invoiceSettings', []));")
        ->toContain('$merged = $this->normalizeInvoiceSettings(array_merge($current, $invoiceSettings));');
});

test('invoice defaults use payment terms before legacy due date offset', function () {
    $model      = file_get_contents(__DIR__ . '/../src/Models/Invoice.php');
    $controller = file_get_contents(__DIR__ . '/../../addon/controllers/billing/invoices/index/new.js');

    expect($model)
        ->toContain("if (isset(\$settings['payment_terms_days']))")
        ->toContain("\$paymentTermsDays = (int) \$settings['payment_terms_days'];")
        ->toContain("} elseif (isset(\$settings['due_date_offset_days']))")
        ->toContain("\$paymentTermsDays = (int) \$settings['due_date_offset_days'];")
        ->toContain('&& $paymentTermsDays > 0')
        ->toContain('addDays($paymentTermsDays)');

    expect($controller)
        ->toContain('const terms = invoiceSettings.payment_terms_days ?? invoiceSettings.due_date_offset_days;')
        ->toContain('due.setDate(due.getDate() + Number(terms));');
});

test('invoice auto send on creation is shared and non blocking', function () {
    $invoiceController = file_get_contents(__DIR__ . '/../src/Http/Controllers/Internal/v1/InvoiceController.php');
    $invoiceService    = file_get_contents(__DIR__ . '/../src/Services/InvoiceService.php');

    expect($invoiceController)
        ->toContain('autoSendOnCreation($record)');

    expect($invoiceService)
        ->toContain('public function autoSendOnCreation(Invoice $invoice): Invoice')
        ->toContain("data_get(\$settings, 'auto_send_on_creation', false)")
        ->toContain('return $this->send($invoice);')
        ->toContain('catch (\\Throwable $e)')
        ->toContain('$invoice->saveQuietly();')
        ->toContain("Log::channel('ledger')->warning('[Ledger] Invoice auto-send on creation failed.'")
        ->toContain('return $this->autoSendOnCreation($invoice);');
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

test('transactions use two-axis lifecycle and settlement status contract', function () {
    $coreTransaction = file_get_contents(dirname(__DIR__, 3) . '/core-api/src/Models/Transaction.php');
    $invoiceService  = file_get_contents(__DIR__ . '/../src/Services/InvoiceService.php');
    $walletService   = file_get_contents(__DIR__ . '/../src/Services/WalletService.php');
    $filter          = file_get_contents(__DIR__ . '/../src/Http/Filter/TransactionFilter.php');
    $resource        = file_get_contents(__DIR__ . '/../src/Http/Resources/v1/Transaction.php');
    $controller      = file_get_contents(__DIR__ . '/../../addon/controllers/payments/transactions/index.js');

    expect($coreTransaction)
        ->toContain('public const SETTLEMENT_STATUS_UNPAID')
        ->toContain('public const SETTLEMENT_STATUS_PAID')
        ->toContain('public const SETTLEMENT_STATUS_REFUNDED')
        ->toContain("'settlement_status'");

    expect($invoiceService)
        ->toContain("'status'             => Transaction::STATUS_SUCCESS")
        ->toContain("'settlement_status'  => Transaction::SETTLEMENT_STATUS_PAID")
        ->toContain("'settled_at'         => now()");

    expect($walletService)
        ->toContain("'status'                 => Transaction::STATUS_SUCCESS")
        ->toContain("'settlement_status'      => Transaction::SETTLEMENT_STATUS_PAID")
        ->not->toContain("'status'                 => 'completed'");

    expect($filter)->toContain('function settlementStatus(?string $settlementStatus)');
    expect($resource)->toContain("'settlement_status'           => \$this->settlement_status");
    expect($controller)
        ->toContain("'settlement_status'")
        ->toContain("filterOptions: ['pending', 'success', 'failed', 'cancelled', 'voided', 'reversed', 'expired']")
        ->not->toContain("'succeeded'");
});

test('invoice filter supports table filter params', function () {
    $filter = file_get_contents(__DIR__ . '/../src/Http/Filter/InvoiceFilter.php');

    expect($filter)
        ->toContain('function status(?string $status)')
        ->toContain('function currency(?string $currency)')
        ->toContain('function order(?string $order)')
        ->toContain('function orderUuid(?string $order)')
        ->toContain('function customer(?string $customer)')
        ->toContain('function customerUuid(?string $customer)')
        ->toContain('function amount(?string $amount)')
        ->toContain('function createdAt($createdAt)')
        ->toContain('function dueDate($dueDate)')
        ->toContain('Utils::dateRange($createdAt)')
        ->toContain('Utils::dateRange($dueDate)')
        ->toContain("whereBetween('total_amount'")
        ->toContain("where('total_amount', '>=', \$min)")
        ->toContain("where('total_amount', '<=', \$max)")
        ->toContain("where('currency', strtoupper(\$currency))")
        ->toContain("where('order_uuid', \$order)")
        ->toContain("where('public_id', \$order)")
        ->toContain("where('tracking_number', \$order)");
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
