<?php

/**
 * TalerDriverTest.
 *
 * Unit tests for the GNU Taler payment gateway driver.
 *
 * These tests use Laravel's fakeTalerHttp() to intercept all outbound HTTP calls
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

function fakeTalerHttp(array $callbacks): void
{
    Http::swap(new HttpFactory());
    Http::fake($callbacks);
}

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

function talerAuthorizationHeader($httpRequest): ?string
{
    $header = $httpRequest->headers()['Authorization'] ?? null;

    return is_array($header) ? ($header[0] ?? null) : $header;
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
    fakeTalerHttp([
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
    fakeTalerHttp([
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
    fakeTalerHttp([
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
    fakeTalerHttp([
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
    fakeTalerHttp([
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
    fakeTalerHttp([
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
    fakeTalerHttp([
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
    fakeTalerHttp([
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
    fakeTalerHttp([
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

test('handleWebhook_uses_contract_amount_when_deposit_total_is_zero', function () {
    fakeTalerHttp([
        'https://backend.example.taler.net/instances/testmerchant/private/orders/TALER-ORDER-DEPOSIT-ZERO' => Http::response(
            [
                'order_status'   => 'paid',
                'deposit_total'  => 'KUDOS:0',
                'contract_terms' => [
                    'amount'  => 'KUDOS:0.5',
                    'summary' => 'Invoice TALER-DEMO-20A32F23F9DA',
                ],
            ],
            200
        ),
    ]);

    $request = Request::create('/ledger/webhooks/taler', 'POST', [
        'order_id' => 'TALER-ORDER-DEPOSIT-ZERO',
    ]);

    $response = talerDriver()->handleWebhook($request);

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->eventType)->toBe(GatewayResponse::EVENT_PAYMENT_SUCCEEDED)
        ->and($response->amount)->toBe(50)
        ->and($response->currency)->toBe('KUDOS');
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
    fakeTalerHttp([
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
    fakeTalerHttp([
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
    fakeTalerHttp([
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
    fakeTalerHttp([
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
    fakeTalerHttp([
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
    fakeTalerHttp([
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
    fakeTalerHttp([
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
    fakeTalerHttp([
        'https://backend.example.taler.net/instances/testmerchant/private/orders' => Http::response(['orders' => []], 200),
    ]);

    $result = talerDriver()->testCredentials();

    expect($result['ok'])->toBeTrue()
        ->and($result['status'])->toBe('ok')
        ->and($result['http_status'])->toBe(200);
});

test('testCredentials_sends_secret_token_as_bearer_token', function () {
    fakeTalerHttp([
        'https://backend.example.taler.net/instances/testmerchant/private/orders' => Http::response(['orders' => []], 200),
    ]);

    talerDriver(['api_token' => 'secret-token:abc'])->testCredentials();

    Http::assertSent(fn ($httpRequest) => talerAuthorizationHeader($httpRequest) === 'Bearer secret-token:abc');
});

test('testCredentials_accepts_pasted_bearer_secret_token', function () {
    fakeTalerHttp([
        'https://backend.example.taler.net/instances/testmerchant/private/orders' => Http::response(['orders' => []], 200),
    ]);

    talerDriver(['api_token' => 'Bearer secret-token:abc'])->testCredentials();

    Http::assertSent(fn ($httpRequest) => talerAuthorizationHeader($httpRequest) === 'Bearer secret-token:abc');
});

test('testCredentials_trims_token_whitespace', function () {
    fakeTalerHttp([
        'https://backend.example.taler.net/instances/testmerchant/private/orders' => Http::response(['orders' => []], 200),
    ]);

    talerDriver(['api_token' => '  Bearer secret-token:abc  '])->testCredentials();

    Http::assertSent(fn ($httpRequest) => talerAuthorizationHeader($httpRequest) === 'Bearer secret-token:abc');
});

test('testCredentials_returns_sanitized_taler_failure_metadata', function () {
    fakeTalerHttp([
        'https://backend.example.taler.net/instances/testmerchant/private/orders' => Http::response([
            'code'        => 2000,
            'hint'        => 'token does not grant access to this instance',
            'detail'      => 'wrong instance',
            'request_uid' => 'req-123',
        ], 403),
    ]);

    $result = talerDriver(['api_token' => 'secret-token:abc'])->testCredentials();

    expect($result['ok'])->toBeFalse()
        ->and($result['status'])->toBe('failed')
        ->and($result['http_status'])->toBe(403)
        ->and($result['metadata']['backend_url'])->toBe('https://backend.example.taler.net')
        ->and($result['metadata']['instance_id'])->toBe('testmerchant')
        ->and($result['metadata']['http_status'])->toBe(403)
        ->and($result['metadata']['taler_error_code'])->toBe(2000)
        ->and($result['metadata']['hint'])->toBe('token does not grant access to this instance')
        ->and($result['metadata']['detail'])->toBe('wrong instance')
        ->and($result['metadata']['request_uid'])->toBe('req-123')
        ->and($result)->not->toHaveKey('raw_response');
});

test('registerWebhook_posts_tenant_safe_body_template', function () {
    fakeTalerHttp([
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
        $body     = $httpRequest->data();
        $template = $body['body_template'] ?? '';

        return str_contains($template, 'company-uuid-1')
            && str_contains($template, 'gateway_public_1')
            && str_contains($template, '{{ order_id }}')
            && str_contains($template, '{{ webhook_type }}');
    });
});

test('createTestOrder_uses_deterministic_test_order_metadata', function () {
    fakeTalerHttp([
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
        ->and($response->gatewayTransactionId)->toBe('ledger-test-returned')
        ->and($response->data['taler_pay_uri'])->toBe('taler://pay/test');
});

test('fetchOrderStatus_returns_provider status and response data', function () {
    fakeTalerHttp([
        'https://backend.example.taler.net/instances/testmerchant/private/orders/order-status-1*' => Http::response([
            'order_status' => 'paid',
            'refund_taken' => 'USD:1.00',
        ], 200),
    ]);

    $result = talerDriver()->fetchOrderStatus('order-status-1', ['timeout_ms' => 1000]);

    expect($result)->toBe([
        'ok'          => true,
        'http_status' => 200,
        'data'        => [
            'order_status' => 'paid',
            'refund_taken' => 'USD:1.00',
        ],
    ]);
});

test('fetchRefundStatus_requests wallet acceptance with cumulative amount', function () {
    fakeTalerHttp([
        'https://backend.example.taler.net/instances/testmerchant/private/orders/order-refund-1*' => Http::response([
            'refund_pending' => 'USD:0',
            'refund_taken'   => 'USD:12.50',
        ], 200),
    ]);

    $result = talerDriver()->fetchRefundStatus(
        'order-refund-1',
        1250,
        'usd',
        ['timeout_ms' => 5000]
    );

    expect($result['ok'])->toBeTrue();

    Http::assertSent(function ($httpRequest) {
        return $httpRequest->method() === 'GET'
            && $httpRequest->data() === [
                'await_refund_obtained' => 'yes',
                'timeout_ms'            => 5000,
                'refund'                => 'USD:12.50',
            ];
    });
});

test('fetchRefundStatus omits refund amount when currency is unavailable', function () {
    fakeTalerHttp([
        'https://backend.example.taler.net/instances/testmerchant/private/orders/order-refund-2*' => Http::response([], 202),
    ]);

    $result = talerDriver()->fetchRefundStatus('order-refund-2', 500);

    expect($result['ok'])->toBeTrue()
        ->and($result['http_status'])->toBe(202);

    Http::assertSent(fn ($httpRequest) => !array_key_exists('refund', $httpRequest->data()));
});

test('purchase reports transport failures during order creation and status lookup', function () {
    $request = new PurchaseRequest(
        amount: 1000,
        currency: 'USD',
        description: 'Transport failure',
    );

    fakeTalerHttp([
        'https://backend.example.taler.net/instances/testmerchant/private/orders' => function () {
            throw new RuntimeException('merchant backend offline');
        },
    ]);

    $createFailure = talerDriver()->purchase($request);

    expect($createFailure->isFailed())->toBeTrue()
        ->and($createFailure->message)->toBe('Taler order creation failed: merchant backend offline');

    fakeTalerHttp([
        'https://backend.example.taler.net/instances/testmerchant/private/orders' => Http::response([
            'order_id' => 'order-created-no-status',
        ], 200),
        'https://backend.example.taler.net/instances/testmerchant/private/orders/order-created-no-status' => function () {
            throw new RuntimeException('status endpoint offline');
        },
    ]);

    $statusFailure = talerDriver()->purchase($request);

    expect($statusFailure->isFailed())->toBeTrue()
        ->and($statusFailure->gatewayTransactionId)->toBe('order-created-no-status')
        ->and($statusFailure->message)->toContain('payment URI retrieval failed: status endpoint offline');
});

test('webhook and refund report transport failures without leaking exceptions', function () {
    fakeTalerHttp([
        'https://backend.example.taler.net/instances/testmerchant/private/orders/order-webhook-error' => function () {
            throw new RuntimeException('verification unavailable');
        },
    ]);

    $webhook = talerDriver()->handleWebhook(Request::create('/webhook', 'POST', [
        'order_id' => 'order-webhook-error',
    ]));

    expect($webhook->isFailed())->toBeTrue()
        ->and($webhook->message)->toBe('Taler webhook verification failed: verification unavailable');

    fakeTalerHttp([
        'https://backend.example.taler.net/instances/testmerchant/private/orders/order-refund-error/refund' => function () {
            throw new RuntimeException('refund unavailable');
        },
    ]);

    $refund = talerDriver()->refund(new RefundRequest(
        gatewayTransactionId: 'order-refund-error',
        amount: 500,
        currency: 'USD',
    ));

    expect($refund->isFailed())->toBeTrue()
        ->and($refund->eventType)->toBe(GatewayResponse::EVENT_REFUND_FAILED)
        ->and($refund->message)->toBe('Taler refund failed: refund unavailable');
});

test('refund exposes wallet acceptance URI and refund metadata', function () {
    fakeTalerHttp([
        'https://backend.example.taler.net/instances/testmerchant/private/orders/order-refund-uri/refund' => Http::response([
            'taler_refund_uri' => 'taler://refund/example/order-refund-uri',
        ], 200),
    ]);

    $refund = talerDriver()->refund(new RefundRequest(
        gatewayTransactionId: 'order-refund-uri',
        amount: 750,
        currency: 'KUDOS',
        reason: null,
        invoiceUuid: 'invoice-1',
        metadata: ['refund_kind' => 'partial'],
    ));

    expect($refund->isSuccessful())->toBeTrue()
        ->and($refund->data['taler_refund_uri'])->toBe('taler://refund/example/order-refund-uri')
        ->and($refund->data['refund_status'])->toBe('wallet_uri_returned')
        ->and($refund->data['wallet_status'])->toBe('pending_wallet_acceptance')
        ->and($refund->data['refund_kind'])->toBe('partial');
});

test('taler admin operations return configuration and transport failures', function () {
    $unconfigured = talerDriver(['api_token' => '']);

    expect($unconfigured->testCredentials())
        ->toMatchArray(['ok' => false, 'status' => 'failed'])
        ->and($unconfigured->registerWebhook())
        ->toMatchArray(['ok' => false, 'status' => 'failed']);

    fakeTalerHttp([
        'https://backend.example.taler.net/instances/testmerchant/private/orders' => function () {
            throw new RuntimeException('credential endpoint offline');
        },
    ]);

    expect(talerDriver()->testCredentials()['message'])
        ->toBe('Taler credential check failed: credential endpoint offline');

    fakeTalerHttp([
        'https://backend.example.taler.net/instances/testmerchant/private/webhooks' => function () {
            throw new RuntimeException('webhook endpoint offline');
        },
    ]);

    expect(talerDriver()->registerWebhook(['webhook_url' => 'https://api.example.test/webhook']))
        ->toMatchArray([
            'ok'      => false,
            'status'  => 'failed',
            'message' => 'Taler webhook registration failed: webhook endpoint offline',
        ]);
});

test('registerWebhook updates an existing hook and reports provider rejection', function () {
    fakeTalerHttp([
        'https://backend.example.taler.net/instances/testmerchant/private/webhooks'                      => Http::response([], 409),
        'https://backend.example.taler.net/instances/testmerchant/private/webhooks/fleetbase-ledger-pay' => Http::response([], 204),
    ]);

    $updated = talerDriver()->registerWebhook(['webhook_url' => 'https://api.example.test/webhook']);

    expect($updated['ok'])->toBeTrue()
        ->and($updated['http_status'])->toBe(204);

    Http::assertSent(fn ($httpRequest) => $httpRequest->method() === 'PATCH');

    fakeTalerHttp([
        'https://backend.example.taler.net/instances/testmerchant/private/webhooks' => Http::response([
            'code' => 4000,
        ], 422),
    ]);

    $rejected = talerDriver()->registerWebhook(['webhook_url' => 'https://api.example.test/webhook']);

    expect($rejected['ok'])->toBeFalse()
        ->and($rejected['status'])->toBe('failed')
        ->and($rejected['http_status'])->toBe(422)
        ->and($rejected['message'])->toBe('Taler webhook registration failed.');
});

test('credential failure messages include detail fallback and safe generic guidance', function () {
    fakeTalerHttp([
        'https://backend.example.taler.net/instances/testmerchant/private/orders' => Http::response([
            'details' => ['field' => 'api_token'],
        ], 401),
    ]);

    $detailed = talerDriver()->testCredentials();

    expect($detailed['message'])->toContain('{"field":"api_token"}');

    fakeTalerHttp([
        'https://backend.example.taler.net/instances/testmerchant/private/orders' => Http::response('not json', 400),
    ]);

    $generic = talerDriver()->testCredentials();

    expect($generic['message'])->toBe('Taler credentials rejected. HTTP 400. Check the API token and Merchant Backend instance ID.')
        ->and($generic['metadata'])->not->toHaveKey('taler_error_code');
});

test('purchase includes an optional fulfillment URL in the signed order', function () {
    fakeTalerHttp([
        'https://backend.example.taler.net/instances/testmerchant/private/orders' => Http::response([
            'order_id' => 'order-with-return-url',
        ], 200),
        'https://backend.example.taler.net/instances/testmerchant/private/orders/order-with-return-url' => Http::response([
            'taler_pay_uri' => 'taler://pay/order-with-return-url',
        ], 200),
    ]);

    talerDriver()->purchase(new PurchaseRequest(
        amount: 500,
        currency: 'KUDOS',
        description: 'Return URL test',
        returnUrl: 'https://console.example.test/invoices/1',
    ));

    Http::assertSent(fn ($httpRequest) => $httpRequest->method() !== 'POST'
        || data_get($httpRequest->data(), 'order.fulfillment_url') === 'https://console.example.test/invoices/1');
});

test('webhook and refund preserve operation-specific configuration failures', function () {
    $driver = talerDriver(['api_token' => '']);

    $webhook = $driver->handleWebhook(Request::create('/webhook', 'POST', [
        'order_id' => 'order-1',
    ]));
    $refund = $driver->refund(new RefundRequest(
        gatewayTransactionId: 'order-1',
        amount: 100,
        currency: 'USD',
    ));

    expect($webhook->eventType)->toBe(GatewayResponse::EVENT_PAYMENT_FAILED)
        ->and($webhook->message)->toBe('Taler API token is not configured.')
        ->and($refund->eventType)->toBe(GatewayResponse::EVENT_REFUND_FAILED)
        ->and($refund->message)->toBe('Taler API token is not configured.');
});

test('webhook safely normalizes absent and malformed taler amounts', function () {
    fakeTalerHttp([
        'https://backend.example.taler.net/instances/testmerchant/private/orders/order-no-amount' => Http::response([
            'order_status'   => 'paid',
            'contract_terms' => [],
        ], 200),
        'https://backend.example.taler.net/instances/testmerchant/private/orders/order-bad-amount' => Http::response([
            'order_status'  => 'paid',
            'deposit_total' => 'not-an-amount',
        ], 200),
    ]);

    $absent = talerDriver()->handleWebhook(Request::create('/webhook', 'POST', [
        'order_id' => 'order-no-amount',
    ]));
    $malformed = talerDriver()->handleWebhook(Request::create('/webhook', 'POST', [
        'order_id' => 'order-bad-amount',
    ]));

    expect($absent->isSuccessful())->toBeTrue()
        ->and($absent->amount)->toBe(0)
        ->and($absent->currency)->toBe('USD')
        ->and($malformed->isSuccessful())->toBeTrue()
        ->and($malformed->amount)->toBe(0)
        ->and($malformed->currency)->toBe('USD');
});
