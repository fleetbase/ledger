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
    $coreTransactionPath = collect([
        dirname(__DIR__, 3) . '/core-api/src/Models/Transaction.php',
        dirname(__DIR__, 2) . '/server_vendor/fleetbase/core-api/src/Models/Transaction.php',
    ])->first(fn ($path) => is_file($path));

    expect($coreTransactionPath)->not->toBeNull();

    $coreTransaction = file_get_contents($coreTransactionPath);
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

test('transaction settlement currency supports taler sandbox currency codes', function () {
    $fallbackMigration = file_get_contents(__DIR__ . '/../migrations/2024_01_01_000016_extend_transactions_table_for_ledger.php');
    $widenMigration    = file_get_contents(__DIR__ . '/../migrations/2026_07_17_000001_widen_transaction_settled_currency_for_taler.php');

    expect($fallbackMigration)
        ->toContain("\$table->string('settled_currency', 10)");

    expect($widenMigration)
        ->toContain('changeSettledCurrencyLength(10)')
        ->toContain("\$table->string('settled_currency', \$length)->nullable()->change()");
});

test('taler gateway lifecycle routes and diagnostics are registered', function () {
    $routes     = file_get_contents(__DIR__ . '/../src/routes.php');
    $controller = file_get_contents(__DIR__ . '/../src/Http/Controllers/Internal/v1/GatewayController.php');
    $driver     = file_get_contents(__DIR__ . '/../src/Gateways/TalerDriver.php');
    $gateway    = file_get_contents(__DIR__ . '/../src/Models/Gateway.php');
    $migration  = file_get_contents(__DIR__ . '/../migrations/2026_07_17_000002_add_meta_to_ledger_gateways_table.php');
    $resource   = file_get_contents(__DIR__ . '/../src/Http/Resources/v1/GatewayTransaction.php');
    $authSchema = file_get_contents(__DIR__ . '/../src/Auth/Schemas/Ledger.php');

    expect($routes)
        ->toContain("'gateways/summary'")
        ->toContain("\$router->post('gateways/test-credentials', 'GatewayController@testDraftCredentials');")
        ->toContain("'{id}/test-credentials'")
        ->toContain("'{id}/create-test-order'")
        ->toContain("'{id}/register-webhook'")
        ->toContain("'{id}/diagnostics'")
        ->toContain("\$router->post('gateways/test-credentials', 'GatewayController@testDraftCredentials');\n\n                                \$router->fleetbaseRoutes");

    expect($controller)
        ->toContain('public function summary')
        ->toContain("'webhook_warnings'")
        ->toContain("'last_payment_at'")
        ->toContain('public function testDraftCredentials')
        ->toContain("'driver'      => ['required', 'string']")
        ->toContain("'config'      => ['required', 'array']")
        ->toContain("->driver(\$validated['driver'])")
        ->toContain('sanitizeProviderResult($driver->testCredentials())')
        ->toContain('public function testCredentials')
        ->toContain('public function createTestOrder')
        ->toContain('public function registerWebhook')
        ->toContain('public function diagnostics')
        ->toContain('recordGatewayDiagnostic')
        ->toContain('sanitizeProviderResult')
        ->toContain("'last_credential_tested_at'")
        ->toContain("'last_credential_test_message'")
        ->toContain("'last_webhook_registration_at'")
        ->toContain("'last_test_order_at'")
        ->toContain("'last_test_order_id'")
        ->toContain("'metadata'    => \$result['metadata'] ?? null")
        ->not->toContain("'credential_status'        => 'not_checked'");

    expect($gateway)
        ->toContain("'meta'")
        ->toContain("'meta'         => 'array'");

    expect($migration)
        ->toContain("Schema::hasColumn('ledger_gateways', 'meta')")
        ->toContain("\$table->json('meta')->nullable()");

    expect($driver)
        ->toContain('public function testCredentials')
        ->toContain('public function createTestOrder')
        ->toContain('public function registerWebhook')
        ->toContain("'company_uuid' => \$companyUuid")
        ->toContain("'gateway_id'   => \$gatewayId");

    expect($resource)
        ->toContain("'gateway_reference_id'")
        ->toContain("'refund_status'")
        ->toContain("'reconciliation_status'");

    expect($authSchema)
        ->toContain("'summary'")
        ->toContain("'test-credentials'")
        ->toContain("'create-test-order'")
        ->toContain("'register-webhook'")
        ->toContain("'diagnostics'");
});

test('payment gateway management renders hub catalog and full page details', function () {
    $routes             = file_get_contents(__DIR__ . '/../../addon/routes.js');
    $indexController    = file_get_contents(__DIR__ . '/../../addon/controllers/payments/gateways/index.js');
    $indexTemplate      = file_get_contents(__DIR__ . '/../../addon/templates/payments/gateways/index.hbs');
    $hub                = file_get_contents(__DIR__ . '/../../addon/components/gateway/hub.hbs');
    $catalogCard        = file_get_contents(__DIR__ . '/../../addon/components/gateway/catalog-card.hbs');
    $providerCell       = file_get_contents(__DIR__ . '/../../addon/components/table/cell/gateway-provider.hbs');
    $detailsTemplate    = file_get_contents(__DIR__ . '/../../addon/templates/payments/gateways/details.hbs');
    $detailsComponent   = file_get_contents(__DIR__ . '/../../addon/components/gateway/details.hbs');
    $detailsComponentJs = file_get_contents(__DIR__ . '/../../addon/components/gateway/details.js');
    $detailsController  = file_get_contents(__DIR__ . '/../../addon/controllers/payments/gateways/details.js');
    $styles             = file_get_contents(__DIR__ . '/../../addon/styles/ledger-engine.css');
    $gatewayRoutes      = "this.route('gateways', function () {
            this.route('index', { path: '/' });
            this.route('new');
            this.route('edit', { path: '/edit/:id' });
            this.route('details', { path: '/:id' }, function () {
                this.route('index', { path: '/' });
                this.route('setup');
                this.route('diagnostics');
                this.route('webhooks');
            });
        });";

    expect($routes)
        ->toContain($gatewayRoutes)
        ->not->toContain("this.route('gateways', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('edit', { path: '/:id/edit' });");

    expect($indexController)
        ->toContain("this.fetch.get('gateways/summary'")
        ->toContain("filterOptions: ['taler', 'stripe', 'cash', 'qpay']")
        ->toContain("cellComponent: 'table/cell/gateway-provider'");

    expect($indexTemplate)
        ->toContain('<Gateway::Hub')
        ->toContain('@summary={{this.summary}}')
        ->toContain('@drivers={{this.drivers}}');

    expect($hub)
        ->toContain('Payment Gateways')
        ->toContain('ledger-gateway-connections-panel')
        ->toContain('@searchInputClass="ledger-gateway-connections-search"')
        ->toContain('Connected Gateways')
        ->toContain('Supported Gateways')
        ->toContain('<Layout::Resource::Tabular')
        ->toContain('<Gateway::CatalogCard');

    expect($styles)
        ->toContain('.ledger-gateway-connections-header')
        ->toContain('body[data-theme=\'dark\'] .ledger-gateway-connections-panel .next-table-wrapper table tbody tr td')
        ->toContain('.ledger-gateway-kpi-accent-green')
        ->toContain('.ledger-gateway-kpi-accent-amber');

    expect($catalogCard)
        ->toContain('Manage gateway')
        ->toContain('Connect gateway');

    expect($providerCell)
        ->toContain('this.gateway.name')
        ->toContain('this.subtitle');

    expect($detailsTemplate)
        ->toContain('Gateway sections')
        ->toContain('{{#each this.tabs as |tab|}}')
        ->toContain("{{if tab.active 'border-blue-500 text-gray-900 dark:text-white'")
        ->toContain("{{if this.isTransactionsTab 'min-w-0'")
        ->not->toContain('Layout::Resource::Panel');

    expect($detailsController)
        ->toContain('currentRouteName')
        ->toContain('isTransactionsTab')
        ->toContain('payments.gateways.details.index')
        ->toContain('payments.gateways.details.setup')
        ->toContain('payments.gateways.details.diagnostics');

    $newTemplate    = file_get_contents(__DIR__ . '/../../addon/templates/payments/gateways/new.hbs');
    $editTemplate   = file_get_contents(__DIR__ . '/../../addon/templates/payments/gateways/edit.hbs');
    $editController = file_get_contents(__DIR__ . '/../../addon/controllers/payments/gateways/edit.js');
    $formComponent  = file_get_contents(__DIR__ . '/../../addon/components/gateway/form.js');
    $formTemplate   = file_get_contents(__DIR__ . '/../../addon/components/gateway/form.hbs');

    expect($newTemplate)
        ->toContain('Connect Payment Gateway')
        ->toContain('<Gateway::Form')
        ->not->toContain('Layout::Resource::Panel');

    expect($editTemplate)
        ->toContain('Edit {{@model.name}}')
        ->toContain('<Gateway::Form')
        ->not->toContain('Layout::Resource::Panel');

    expect($editController)
        ->toContain('transitionToGatewayDetails')
        ->toContain("this.hostRouter.transitionTo('console.ledger.payments.gateways.details', gateway)");

    expect($formTemplate)
        ->toContain('Choose Payment Gateway')
        ->toContain('this.currentStep')
        ->toContain('Gateway Credentials')
        ->toContain('Routing And Status')
        ->toContain('Review Gateway Setup');

    expect($formComponent)
        ->toContain('this.args.initialDriverCode')
        ->toContain('shouldReplaceWebhookUrl')
        ->toContain("resource.set?.('webhook_url', driver.webhook_url)")
        ->toContain('this.loadSchema.perform(driver.code, true)')
        ->toContain('get canTestCredentials()')
        ->toContain("const endpoint = this.args.resource?.id ? `gateways/\${this.args.resource.id}/test-credentials` : 'gateways/test-credentials'")
        ->toContain('driver: this.args.resource?.driver')
        ->toContain('environment: this.args.resource?.environment ??')
        ->toContain('config: this.configValues')
        ->not->toContain('Save this gateway before testing credentials')
        ->not->toContain('this.availableDrivers[0]');

    expect($formTemplate)
        ->toContain('class="whitespace-nowrap"')
        ->toContain('@disabled={{or this.testCredentials.isRunning (not this.canTestCredentials)}}')
        ->not->toContain('(not @resource.id)');

    expect($detailsComponent)
        ->toContain('Provider Status')
        ->toContain('Recent Gateway Activity')
        ->toContain('Gateway Setup')
        ->toContain('Provider Tools')
        ->toContain('format-date-fns')
        ->toContain('setupIdentityRows')
        ->toContain('setupRoutingRows')
        ->toContain('setupConfigRows')
        ->toContain('diagnosticsResults')
        ->toContain('<ClickToCopy')
        ->toContain('@size="sm"')
        ->not->toContain('Gateway Operations')
        ->not->toContain('Operational Health')
        ->not->toContain('Customer Payment Preview')
        ->not->toContain('QR code / wallet redirect appears here');

    expect($detailsComponentJs)
        ->toContain('runDiagnosticAction')
        ->toContain('confirmTitle')
        ->toContain('copySystemWebhookUrl')
        ->toContain('diagnosticActions')
        ->toContain('providerStatusRows')
        ->toContain('recentActivity')
        ->toContain('lastCredentialTestedAt')
        ->toContain('overviewActions');
});

test('invoice refund workflow routes controller and ui are registered', function () {
    $routes          = file_get_contents(__DIR__ . '/../src/routes.php');
    $authSchema      = file_get_contents(__DIR__ . '/../src/Auth/Schemas/Ledger.php');
    $controller      = file_get_contents(__DIR__ . '/../src/Http/Controllers/Internal/v1/InvoiceController.php');
    $paymentService  = file_get_contents(__DIR__ . '/../src/Services/PaymentService.php');
    $ui              = file_get_contents(__DIR__ . '/../../addon/controllers/billing/invoices/index/details.js');
    $modal           = file_get_contents(__DIR__ . '/../../addon/components/modals/issue-refund.hbs');
    $result          = file_get_contents(__DIR__ . '/../../addon/components/modals/refund-result.hbs');
    $history         = file_get_contents(__DIR__ . '/../../addon/components/modals/refund-history.hbs');
    $handoff         = file_get_contents(__DIR__ . '/../../addon/components/customer-taler-refund.hbs');
    $handoffJs       = file_get_contents(__DIR__ . '/../../addon/components/customer-taler-refund.js');
    $extension       = file_get_contents(__DIR__ . '/../../addon/extension.js');
    $modalReexport   = file_get_contents(__DIR__ . '/../../app/components/modals/issue-refund.js');
    $resultReexport  = file_get_contents(__DIR__ . '/../../app/components/modals/refund-result.js');
    $historyReexport = file_get_contents(__DIR__ . '/../../app/components/modals/refund-history.js');
    $handoffReexport = file_get_contents(__DIR__ . '/../../app/components/customer-taler-refund.js');
    $listener        = file_get_contents(__DIR__ . '/../src/Listeners/HandleProcessedRefund.php');
    $publicInvoice   = file_get_contents(__DIR__ . '/../src/Http/Controllers/Public/PublicInvoiceController.php');
    $notification    = file_get_contents(__DIR__ . '/../src/Notifications/RefundUriAvailable.php');
    $mailTemplate    = file_get_contents(__DIR__ . '/../resources/views/mail/refund-uri-available.blade.php');
    $invoiceModel    = file_get_contents(__DIR__ . '/../../addon/models/ledger-invoice.js');
    $customerInvoice = file_get_contents(__DIR__ . '/../../addon/components/customer-invoice.js');

    expect($routes)
        ->toContain("'{id}/refund-options'")
        ->toContain("'{id}/refund'")
        ->toContain("'{id}/refunds/{gatewayTransactionId}/send-refund-uri'")
        ->toContain("'refunds/{refund_id}'");

    expect($authSchema)
        ->toContain("'refund'")
        ->toContain("'refund-options'")
        ->toContain("'send-refund-uri'");

    expect($controller)
        ->toContain('public function refundOptions')
        ->toContain('public function refund(string $id, Request $request, PaymentService $paymentService)')
        ->toContain('public function sendRefundUri')
        ->toContain("'refunds' => \$this->refundGatewayTransactions(\$invoice)")
        ->toContain('serializeRefundGatewayTransaction')
        ->toContain('resolveRefundGatewayTransaction')
        ->toContain('new RefundUriAvailable($invoice, $refund, $uri)')
        ->toContain('refundHandoffUrl')
        ->toContain("'refund_handoff_url'")
        ->toContain("'taler_refund_uri'       => \$refundUri")
        ->toContain('invoicePaymentReferences')
        ->toContain('refundableGatewayTransactions')
        ->toContain('invoiceRemainingRefundableAmount')
        ->toContain('raw_response->original_gateway_reference_id')
        ->toContain('raw_response->data->order_id')
        ->toContain('new RefundRequest(')
        ->toContain("'refund_kind'")
        ->toContain('$refundKind')
        ->toContain("'data'                   => \$response->data");

    expect($paymentService)
        ->toContain("'original_gateway_reference_id' => \$response->gatewayTransactionId")
        ->toContain("\$gatewayReferenceId .= '-refund-' . Str::lower(Str::random(8))");

    expect($ui)
        ->toContain('Issue Refund')
        ->toContain('invoices/${invoice.id}/refund-options')
        ->toContain('invoices/${invoice.id}/refund')
        ->toContain("'paid', 'refunded', 'refund_pending', 'partial_refund_pending', 'void', 'cancelled'")
        ->toContain('confirmRefund(invoice, options, selected, modal)')
        ->toContain('this.modalsManager.confirm')
        ->toContain('Confirm Refund')
        ->toContain('refundInvoice(invoice, options)')
        ->toContain('showRefundResult')
        ->toContain('response.refund?.refund_url ?? responseData.refund_url ?? responseData.taler_refund_uri')
        ->toContain('this.hostRouter.refresh()')
        ->toContain('View Refunds')
        ->toContain('modals/refund-history')
        ->toContain('@tracked refundRows = []')
        ->toContain('sendRefundUri(invoice, refund, email)');

    expect($modal)
        ->toContain('Remaining refundable')
        ->toContain('Refund Type')
        ->toContain('custom for a partial refund')
        ->toContain('GNU Taler may return a refund URI')
        ->toContain('<MoneyInput');

    expect($result)
        ->toContain('Customer Refund Link')
        ->toContain('Wallet URI')
        ->toContain('<ClickToCopy')
        ->toContain('Gateway refund transaction')
        ->toContain('mb-4 rounded-md')
        ->toContain('Send Email')
        ->not->toContain('@type="text" class="w-full form-input" readonly={{true}}');

    expect($history)
        ->toContain('Customer Email')
        ->toContain('<ClickToCopy')
        ->toContain('Send Email')
        ->toContain('refund.taler_refund_uri')
        ->toContain('No refund URI has been issued');

    expect($handoff)
        ->toContain('GNU Taler Refund')
        ->toContain('Open GNU Taler Wallet')
        ->toContain('<ClickToCopy')
        ->toContain('this.qrImageSrc')
        ->toContain('href={{this.talerRefundUri}}');

    expect($handoffJs)
        ->toContain('this.fetch.get(`refunds/${this.refundId}`')
        ->toContain('meta[name="taler-support"]')
        ->toContain('meta[name="taler-uri"]')
        ->toContain("support.content = 'uri'")
        ->toContain('uri.content = this.talerRefundUri');

    expect($extension)
        ->toContain("slug: 'taler-refund'")
        ->toContain("new ExtensionComponent('@fleetbase/ledger-engine', 'customer-taler-refund')");

    expect($modalReexport)
        ->toContain('@fleetbase/ledger-engine/components/modals/issue-refund');

    expect($resultReexport)
        ->toContain('@fleetbase/ledger-engine/components/modals/refund-result');

    expect($historyReexport)
        ->toContain('@fleetbase/ledger-engine/components/modals/refund-history');

    expect($handoffReexport)
        ->toContain('@fleetbase/ledger-engine/components/customer-taler-refund');

    expect($listener)
        ->toContain('$refundReference = $gatewayTransaction->public_id ?: $gatewayTransaction->uuid')
        ->toContain("'reference'          => \$refundReference")
        ->toContain("'refund_reference'         => \$refundReference")
        ->toContain("'pending_wallet_refund_amount'")
        ->toContain('invoiceRefundStatus')
        ->toContain("\$gateway->driver === 'taler'")
        ->toContain("'refund_pending'")
        ->toContain("'partial_refund_pending'")
        ->not->toContain("\$invoice->status = \$refundedAmount >= (int) \$invoice->total_amount ? 'refunded' : 'partial'");

    expect($publicInvoice)
        ->toContain('public function refund(string $refundId)')
        ->toContain("'taler_refund_uri'")
        ->toContain("'qr_image'")
        ->toContain("'refund_pending'")
        ->toContain("'partial_refund_pending'")
        ->toContain('(int) $invoice->balance <= 0');

    expect($notification)
        ->toContain("Utils::consoleUrl('~/taler-refund'")
        ->toContain("'refundUrl'");

    expect($mailTemplate)
        ->toContain('{{ $refundUrl }}')
        ->toContain('{{ $refundUri }}');

    expect($invoiceModel)
        ->toContain('partial_refund_pending')
        ->toContain('refund_pending');

    expect($customerInvoice)
        ->toContain("'refund_pending'")
        ->toContain("'partial_refund_pending'");
});

test('taler webhook unresolved routing is audited', function () {
    $controller = file_get_contents(__DIR__ . '/../src/Http/Controllers/WebhookController.php');

    expect($controller)
        ->toContain("\$driver === 'taler'")
        ->toContain('recordUnresolvedWebhook')
        ->toContain('matched multiple active gateways')
        ->toContain('Taler webhook could not resolve an active gateway.');
});

test('taler refund verification command scheduler and ui are registered', function () {
    $routes     = file_get_contents(__DIR__ . '/../src/routes.php');
    $authSchema = file_get_contents(__DIR__ . '/../src/Auth/Schemas/Ledger.php');
    $controller = file_get_contents(__DIR__ . '/../src/Http/Controllers/Internal/v1/InvoiceController.php');
    $provider   = file_get_contents(__DIR__ . '/../src/Providers/LedgerServiceProvider.php');
    $command    = file_get_contents(__DIR__ . '/../src/Console/Commands/VerifyTalerRefunds.php');
    $service    = file_get_contents(__DIR__ . '/../src/Services/TalerRefundVerificationService.php');
    $driver     = file_get_contents(__DIR__ . '/../src/Gateways/TalerDriver.php');
    $ui         = file_get_contents(__DIR__ . '/../../addon/controllers/billing/invoices/index/details.js');
    $history    = file_get_contents(__DIR__ . '/../../addon/components/modals/refund-history.hbs');

    expect($routes)
        ->toContain("'{id}/refunds/{gatewayTransactionId}/verify-status'");

    expect($authSchema)
        ->toContain("'verify-refund-status'");

    expect($controller)
        ->toContain('public function verifyRefundStatus')
        ->toContain('TalerRefundVerificationService $verifier')
        ->toContain('resolveRefundGatewayTransaction($invoice, $gatewayTransactionId)')
        ->toContain('$verifier->verifyRefund($refund)');

    expect($provider)
        ->toContain('TalerRefundVerificationService::class')
        ->toContain('VerifyTalerRefunds::class')
        ->toContain("command('ledger:taler:verify-refunds')")
        ->toContain('everyFifteenMinutes()')
        ->toContain('withoutOverlapping()');

    expect($command)
        ->toContain('ledger:taler:verify-refunds')
        ->toContain('{--refund=')
        ->toContain('verifyPending')
        ->toContain('Refund verification complete');

    expect($service)
        ->toContain('class TalerRefundVerificationService')
        ->toContain('public function verifyPending')
        ->toContain('public function verifyRefund')
        ->toContain('protected function pendingRefundQuery')
        ->toContain('fetchRefundStatus($orderId, $targetAmount, $refund->currency)')
        ->toContain('cumulativeRefundAmountForOrder')
        ->toContain('refund_taken')
        ->toContain("'pending_wallet_acceptance'")
        ->toContain("'accepted'");

    expect($driver)
        ->toContain('fetchOrderStatus(string $orderId, array $query = [])')
        ->toContain('fetchRefundStatus')
        ->toContain("'await_refund_obtained' => 'yes'")
        ->toContain("'timeout_ms'")
        ->toContain('$pending->get($url, $payload)');

    expect($ui)
        ->toContain('verifyRefundStatus(invoice, refund)')
        ->toContain('verify-status')
        ->toContain('Refund status verified.');

    expect($history)
        ->toContain('Verify Status')
        ->toContain('this.verifyRefundStatus');
});

test('successful payment listener can resolve taler invoice from purchase transaction fallback', function () {
    $listener = file_get_contents(__DIR__ . '/../src/Listeners/HandleSuccessfulPayment.php');

    expect($listener)
        ->toContain('resolveInvoiceUuidFromPurchaseTransaction')
        ->toContain("->where('type', 'purchase')")
        ->toContain("data_get(\$purchaseTransaction?->raw_response, 'invoice_uuid')")
        ->toContain("data_get(\$purchaseTransaction?->raw_response, 'data.invoice_uuid')");
});

test('taler settlement and e2e commands are registered', function () {
    $provider          = file_get_contents(__DIR__ . '/../src/Providers/LedgerServiceProvider.php');
    $settlementCommand = file_get_contents(__DIR__ . '/../src/Console/Commands/VerifyTalerSettlements.php');
    $e2eCommand        = file_get_contents(__DIR__ . '/../src/Console/Commands/TalerSandboxE2E.php');

    expect($provider)
        ->toContain('VerifyTalerSettlements::class')
        ->toContain('TalerSandboxE2E::class');

    expect($settlementCommand)
        ->toContain('ledger:taler:verify-settlements')
        ->toContain('reconciliation_status')
        ->toContain('wire_reconciled');

    expect($e2eCommand)
        ->toContain('ledger:taler:e2e')
        ->toContain('TALER_E2E_ENABLED')
        ->toContain('TALER_E2E_BACKEND_URL');
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
        ->toContain("orWhere('public_id', \$order)")
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
