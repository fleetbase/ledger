<?php

/**
 * TalerDriverTest.
 *
 * Unit tests for the GNU Taler payment gateway driver.
 *
 * These tests use Laravel's Http::fake() to intercept all outbound HTTP calls
 * so no real Taler Merchant Backend is required. Each test group covers one
 * public method of TalerDriver: purchase(), handleWebhook(), and refund().
 *
 * Test naming convention: <method>_<scenario>
 */

use Fleetbase\Ledger\DTO\GatewayResponse;
use Fleetbase\Ledger\DTO\PurchaseRequest;
use Fleetbase\Ledger\DTO\RefundRequest;
use Fleetbase\Ledger\Gateways\TalerDriver;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

beforeEach(function () {
    $app = Container::getInstance();
    $app->instance('http', new HttpFactory());
    Facade::clearResolvedInstance('http');
});

/**
 * Build a fully-initialised TalerDriver using the given config overrides.
 */
function talerDriver(array $config = [], bool $sandbox = false): TalerDriver
{
    $defaults = [
        'backend_url' => 'https://backend.example.taler.net',
        'instance_id' => 'testmerchant',
        'api_token'   => 'secret-token-abc',
    ];

    $driver = new TalerDriver();
    $driver->initialize(array_merge($defaults, $config), $sandbox);

    return $driver;
}

// ---------------------------------------------------------------------------
// Driver metadata
// ---------------------------------------------------------------------------

test('driver returns correct code', function () {
    expect(talerDriver()->getCode())->toBe('taler');
});

test('driver returns correct name', function () {
    expect(talerDriver()->getName())->toBe('GNU Taler');
});

test('driver advertises purchase, refund, and webhooks capabilities', function () {
    $caps = talerDriver()->getCapabilities();

    expect($caps)->toContain('purchase')
                 ->toContain('refund')
                 ->toContain('webhooks');
});

test('driver config schema contains required fields', function () {
    $schema = talerDriver()->getConfigSchema();
    $keys   = array_column($schema, 'key');

    expect($keys)->toContain('backend_url')
                 ->toContain('instance_id')
                 ->toContain('api_token');
});

// ---------------------------------------------------------------------------
// purchase() — happy path
// ---------------------------------------------------------------------------

test('purchase_creates_order_and_returns_pending_response', function () {
    Http::fake([
        // Step 1: order creation
        'https://backend.example.taler.net/instances/testmerchant/private/orders' => Http::response(
            ['order_id' => 'TALER-ORDER-001'],
            200
        ),
        // Step 2: status fetch for taler_pay_uri
        'https://backend.example.taler.net/instances/testmerchant/private/orders/TALER-ORDER-001' => Http::response(
            [
                'order_status'  => 'unpaid',
                'taler_pay_uri' => 'taler://pay/backend.example.taler.net/testmerchant/TALER-ORDER-001',
            ],
            200
        ),
    ]);

    $request = new PurchaseRequest(
        amount: 2500,
        currency: 'USD',
        description: 'Invoice #INV-001',
        invoiceUuid: 'invoice-uuid-abc',
    );

    $response = talerDriver()->purchase($request);

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->isPending())->toBeTrue()
        ->and($response->status)->toBe(GatewayResponse::STATUS_PENDING)
        ->and($response->eventType)->toBe(GatewayResponse::EVENT_PAYMENT_PENDING)
        ->and($response->gatewayTransactionId)->toBe('TALER-ORDER-001')
        ->and($response->data['taler_pay_uri'])->toBe('taler://pay/backend.example.taler.net/testmerchant/TALER-ORDER-001')
        ->and($response->data['payment_url'])->toBe('taler://pay/backend.example.taler.net/testmerchant/TALER-ORDER-001')
        ->and($response->data['qr_text'])->toBe('taler://pay/backend.example.taler.net/testmerchant/TALER-ORDER-001')
        ->and(array_key_exists('qr_image', $response->data))->toBeTrue()
        ->and($response->data['invoice_uuid'])->toBe('invoice-uuid-abc');
});

test('purchase_sends_correct_taler_amount_format', function () {
    Http::fake([
        'https://backend.example.taler.net/instances/testmerchant/private/orders' => Http::response(
            ['order_id' => 'TALER-ORDER-002'],
            200
        ),
        'https://backend.example.taler.net/instances/testmerchant/private/orders/TALER-ORDER-002' => Http::response(
            ['order_status' => 'unpaid', 'taler_pay_uri' => 'taler://pay/...'],
            200
        ),
    ]);

    $request = new PurchaseRequest(
        amount: 1050,   // USD 10.50
        currency: 'USD',
        description: 'Test',
        invoiceUuid: 'inv-001',
    );

    talerDriver()->purchase($request);

    // Assert the POST body contained the correct Taler amount string
    Http::assertSent(function ($httpRequest) {
        $body = $httpRequest->data();

        return isset($body['order']['amount']) && $body['order']['amount'] === 'USD:10.50';
    });
});

test('purchase_embeds_invoice_uuid_in_order_payload', function () {
    Http::fake([
        'https://backend.example.taler.net/instances/testmerchant/private/orders' => Http::response(
            ['order_id' => 'TALER-ORDER-003'],
            200
        ),
        'https://backend.example.taler.net/instances/testmerchant/private/orders/TALER-ORDER-003' => Http::response(
            ['order_status' => 'unpaid', 'taler_pay_uri' => 'taler://pay/...'],
            200
        ),
    ]);

    $request = new PurchaseRequest(
        amount: 500,
        currency: 'EUR',
        description: 'Test',
        invoiceUuid: 'my-invoice-uuid',
    );

    talerDriver()->purchase($request);

    Http::assertSent(function ($httpRequest) {
        $body = $httpRequest->data();

        return isset($body['order']['invoice_uuid']) && $body['order']['invoice_uuid'] === 'my-invoice-uuid';
    });
});

test('purchase_sends_deterministic_order_id', function () {
    Http::fake([
        'https://backend.example.taler.net/instances/testmerchant/private/orders' => Http::response(
            ['order_id' => 'ledger-returned-order-id'],
            200
        ),
        'https://backend.example.taler.net/instances/testmerchant/private/orders/ledger-returned-order-id' => Http::response(
            ['order_status' => 'unpaid', 'taler_pay_uri' => 'taler://pay/...'],
            200
        ),
    ]);

    $request = new PurchaseRequest(
        amount: 500,
        currency: 'EUR',
        description: 'Test',
        invoiceUuid: 'invoice-uuid-for-order-id',
    );

    talerDriver()->purchase($request);

    Http::assertSent(function ($httpRequest) {
        $body = $httpRequest->data();

        return isset($body['order_id'])
            && str_starts_with($body['order_id'], 'ledger-')
            && strlen($body['order_id']) === 39;
    });
});

// ---------------------------------------------------------------------------
// purchase() — failure paths
// ---------------------------------------------------------------------------

test('purchase_returns_failure_when_backend_returns_error', function () {
    Http::fake([
        'https://backend.example.taler.net/instances/testmerchant/private/orders' => Http::response(
            ['error' => 'UNAUTHORIZED'],
            401
        ),
    ]);

    $request = new PurchaseRequest(
        amount: 1000,
        currency: 'USD',
        description: 'Test',
    );

    $response = talerDriver()->purchase($request);

    expect($response->isFailed())->toBeTrue()
        ->and($response->eventType)->toBe(GatewayResponse::EVENT_PAYMENT_FAILED);
});

test('purchase_returns_failure_when_order_id_missing', function () {
    Http::fake([
        'https://backend.example.taler.net/instances/testmerchant/private/orders' => Http::response(
            [],   // no order_id
            200
        ),
    ]);

    $request = new PurchaseRequest(
        amount: 1000,
        currency: 'USD',
        description: 'Test',
    );

    $response = talerDriver()->purchase($request);

    expect($response->isFailed())->toBeTrue();
});

test('purchase_returns_failure_when_payment_uri_missing', function () {
    Http::fake([
        'https://backend.example.taler.net/instances/testmerchant/private/orders' => Http::response(
            ['order_id' => 'TALER-ORDER-NO-URI'],
            200
        ),
        'https://backend.example.taler.net/instances/testmerchant/private/orders/TALER-ORDER-NO-URI' => Http::response(
            ['order_status' => 'unpaid'],
            200
        ),
    ]);

    $request = new PurchaseRequest(
        amount: 1000,
        currency: 'USD',
        description: 'Test',
    );

    $response = talerDriver()->purchase($request);

    expect($response->isFailed())->toBeTrue()
        ->and($response->gatewayTransactionId)->toBe('TALER-ORDER-NO-URI');
});

test('purchase_returns_failure_when_required_config_missing', function () {
    $request = new PurchaseRequest(
        amount: 1000,
        currency: 'USD',
        description: 'Test',
    );

    $response = talerDriver(['backend_url' => ''], false)->purchase($request);

    expect($response->isFailed())->toBeTrue()
        ->and($response->message)->toContain('Backend URL');
});

test('purchase_defaults_to_hosted_fleetbase_taler_in_sandbox_when_backend_url_missing', function () {
    Http::fake([
        'https://merchant.taler.fleetbase.io/instances/testmerchant/private/orders' => Http::response(
            ['order_id' => 'TALER-HOSTED-SANDBOX'],
            200
        ),
        'https://merchant.taler.fleetbase.io/instances/testmerchant/private/orders/TALER-HOSTED-SANDBOX' => Http::response(
            [
                'order_status'  => 'unpaid',
                'taler_pay_uri' => 'taler://pay/merchant.taler.fleetbase.io/testmerchant/TALER-HOSTED-SANDBOX',
            ],
            200
        ),
    ]);

    $request = new PurchaseRequest(
        amount: 1000,
        currency: 'KUDOS',
        description: 'Hosted sandbox test',
    );

    $response = talerDriver(['backend_url' => ''], true)->purchase($request);

    expect($response->isPending())->toBeTrue()
        ->and($response->gatewayTransactionId)->toBe('TALER-HOSTED-SANDBOX')
        ->and($response->data['taler_pay_uri'])->toBe('taler://pay/merchant.taler.fleetbase.io/testmerchant/TALER-HOSTED-SANDBOX');
});

// ---------------------------------------------------------------------------
// handleWebhook() — happy path
// ---------------------------------------------------------------------------

test('handleWebhook_verifies_paid_order_and_returns_success', function () {
    Http::fake([
        'https://backend.example.taler.net/instances/testmerchant/private/orders/TALER-ORDER-001' => Http::response(
            [
                'order_status'   => 'paid',
                'deposit_total'  => 'USD:25.00',
                'contract_terms' => [
                    'invoice_uuid' => 'invoice-uuid-abc',
                    'summary'      => 'Invoice #INV-001',
                ],
                'wired'        => true,
                'last_payment' => '2024-01-15T10:30:00Z',
            ],
            200
        ),
    ]);

    $request = Request::create('/ledger/webhooks/taler', 'POST', [
        'order_id' => 'TALER-ORDER-001',
    ]);

    $response = talerDriver()->handleWebhook($request);

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->eventType)->toBe(GatewayResponse::EVENT_PAYMENT_SUCCEEDED)
        ->and($response->gatewayTransactionId)->toBe('TALER-ORDER-001')
        ->and($response->amount)->toBe(2500)
        ->and($response->currency)->toBe('USD')
        ->and($response->data['invoice_uuid'])->toBe('invoice-uuid-abc');
});

// ---------------------------------------------------------------------------
// handleWebhook() — failure paths
// ---------------------------------------------------------------------------

test('handleWebhook_returns_failure_when_order_id_missing', function () {
    $request = Request::create('/ledger/webhooks/taler', 'POST', []);

    $response = talerDriver()->handleWebhook($request);

    expect($response->isFailed())->toBeTrue()
        ->and($response->eventType)->toBe(GatewayResponse::EVENT_UNKNOWN);
});

test('handleWebhook_returns_failure_when_order_not_paid', function () {
    Http::fake([
        'https://backend.example.taler.net/instances/testmerchant/private/orders/TALER-ORDER-002' => Http::response(
            ['order_status' => 'unpaid'],
            200
        ),
    ]);

    $request = Request::create('/ledger/webhooks/taler', 'POST', [
        'order_id' => 'TALER-ORDER-002',
    ]);

    $response = talerDriver()->handleWebhook($request);

    expect($response->isFailed())->toBeTrue()
        ->and($response->eventType)->toBe(GatewayResponse::EVENT_PAYMENT_FAILED);
});

test('handleWebhook_returns_failure_when_backend_returns_error', function () {
    Http::fake([
        'https://backend.example.taler.net/instances/testmerchant/private/orders/TALER-ORDER-003' => Http::response(
            ['error' => 'NOT_FOUND'],
            404
        ),
    ]);

    $request = Request::create('/ledger/webhooks/taler', 'POST', [
        'order_id' => 'TALER-ORDER-003',
    ]);

    $response = talerDriver()->handleWebhook($request);

    expect($response->isFailed())->toBeTrue();
});

// ---------------------------------------------------------------------------
// refund() — happy path
// ---------------------------------------------------------------------------

test('refund_issues_refund_and_returns_success', function () {
    Http::fake([
        'https://backend.example.taler.net/instances/testmerchant/private/orders/TALER-ORDER-001/refund' => Http::response(
            ['taler_refund_uri' => 'taler://refund/...'],
            200
        ),
    ]);

    $request = new RefundRequest(
        gatewayTransactionId: 'TALER-ORDER-001',
        amount: 2500,
        currency: 'USD',
        reason: 'Customer requested refund',
        invoiceUuid: 'invoice-uuid-abc',
    );

    $response = talerDriver()->refund($request);

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->eventType)->toBe(GatewayResponse::EVENT_REFUND_PROCESSED)
        ->and($response->amount)->toBe(2500)
        ->and($response->currency)->toBe('USD')
        ->and($response->gatewayTransactionId)->toBe('TALER-ORDER-001')
        ->and($response->data['taler_refund_uri'])->toBe('taler://refund/...')
        ->and($response->data['refund_url'])->toBe('taler://refund/...')
        ->and($response->data['refund_status'])->toBe('wallet_uri_returned')
        ->and($response->data['wallet_status'])->toBe('pending_wallet_acceptance');
});

test('refund_sends_correct_taler_amount_format', function () {
    Http::fake([
        'https://backend.example.taler.net/instances/testmerchant/private/orders/TALER-ORDER-001/refund' => Http::response(
            [],
            200
        ),
    ]);

    $request = new RefundRequest(
        gatewayTransactionId: 'TALER-ORDER-001',
        amount: 999,   // USD 9.99
        currency: 'USD',
    );

    talerDriver()->refund($request);

    Http::assertSent(function ($httpRequest) {
        $body = $httpRequest->data();

        return isset($body['refund']) && $body['refund'] === 'USD:9.99';
    });
});

// ---------------------------------------------------------------------------
// refund() — failure paths
// ---------------------------------------------------------------------------

test('refund_returns_failure_when_backend_returns_error', function () {
    Http::fake([
        'https://backend.example.taler.net/instances/testmerchant/private/orders/TALER-ORDER-001/refund' => Http::response(
            ['error' => 'REFUND_EXCEEDS_PAYMENT'],
            409
        ),
    ]);

    $request = new RefundRequest(
        gatewayTransactionId: 'TALER-ORDER-001',
        amount: 99999,
        currency: 'USD',
    );

    $response = talerDriver()->refund($request);

    expect($response->isFailed())->toBeTrue()
        ->and($response->eventType)->toBe(GatewayResponse::EVENT_REFUND_FAILED);
});

// ---------------------------------------------------------------------------
// Amount conversion edge cases
// ---------------------------------------------------------------------------

test('purchase_converts_zero_amount_correctly', function () {
    Http::fake([
        'https://backend.example.taler.net/instances/testmerchant/private/orders' => Http::response(
            ['order_id' => 'TALER-ORDER-ZERO'],
            200
        ),
        'https://backend.example.taler.net/instances/testmerchant/private/orders/TALER-ORDER-ZERO' => Http::response(
            ['order_status' => 'unpaid', 'taler_pay_uri' => 'taler://pay/...'],
            200
        ),
    ]);

    $request = new PurchaseRequest(
        amount: 0,
        currency: 'EUR',
        description: 'Zero amount test',
    );

    talerDriver()->purchase($request);

    Http::assertSent(function ($httpRequest) {
        $body = $httpRequest->data();

        return isset($body['order']['amount']) && $body['order']['amount'] === 'EUR:0.00';
    });
});

test('webhook_parses_taler_amount_with_single_digit_fraction', function () {
    Http::fake([
        'https://backend.example.taler.net/instances/testmerchant/private/orders/TALER-ORDER-FRAC' => Http::response(
            [
                'order_status'   => 'paid',
                'deposit_total'  => 'EUR:5.9',   // single-digit fraction
                'contract_terms' => ['invoice_uuid' => 'inv-frac'],
            ],
            200
        ),
    ]);

    $request = Request::create('/ledger/webhooks/taler', 'POST', [
        'order_id' => 'TALER-ORDER-FRAC',
    ]);

    $response = talerDriver()->handleWebhook($request);

    // EUR:5.9 should be parsed as 590 cents
    expect($response->isSuccessful())->toBeTrue()
        ->and($response->amount)->toBe(590)
        ->and($response->currency)->toBe('EUR');
});

test('testCredentials_checks_private_taler_endpoint', function () {
    Http::fake([
        'https://backend.example.taler.net/instances/testmerchant/private/orders' => Http::response(['orders' => []], 200),
    ]);

    $result = talerDriver()->testCredentials();

    expect($result['ok'])->toBeTrue()
        ->and($result['status'])->toBe('ok')
        ->and($result['http_status'])->toBe(200);
});

test('registerWebhook_posts_tenant_safe_body_template', function () {
    Http::fake([
        'https://backend.example.taler.net/instances/testmerchant/private/webhooks' => Http::response([], 204),
    ]);

    $result = talerDriver()->registerWebhook([
        'webhook_url'  => 'https://api.example.com/ledger/webhooks/taler',
        'company_uuid' => 'company-uuid-1',
        'gateway_id'   => 'gateway_public_1',
        'gateway_uuid' => 'gateway-uuid-1',
    ]);

    expect($result['ok'])->toBeTrue()
        ->and($result['status'])->toBe('registered');

    Http::assertSent(function ($httpRequest) {
        $body = $httpRequest->data();
        $template = $body['body_template'] ?? '';

        return str_contains($template, 'company-uuid-1')
            && str_contains($template, 'gateway_public_1')
            && str_contains($template, '${ORDER_ID}');
    });
});

test('createTestOrder_uses_deterministic_test_order_metadata', function () {
    Http::fake([
        'https://backend.example.taler.net/instances/testmerchant/private/orders' => Http::response(
            ['order_id' => 'ledger-test-returned'],
            200
        ),
        'https://backend.example.taler.net/instances/testmerchant/private/orders/ledger-test-returned' => Http::response(
            ['order_status' => 'unpaid', 'taler_pay_uri' => 'taler://pay/test'],
            200
        ),
    ]);

    $response = talerDriver()->createTestOrder(['amount' => 1, 'currency' => 'KUDOS']);

    expect($response->isPending())->toBeTrue()
        ->and($response->data['taler_pay_uri'])->toBe('taler://pay/test');

    Http::assertSent(function ($httpRequest) {
        $body = $httpRequest->data();

        return isset($body['order_id'])
            && str_starts_with($body['order_id'], 'ledger-test-')
            && data_get($body, 'order.metadata.test_order') === true;
    });
});
