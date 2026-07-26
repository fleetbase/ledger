<?php

use Fleetbase\Ledger\DTO\GatewayResponse;
use Fleetbase\Ledger\DTO\PurchaseRequest;
use Fleetbase\Ledger\DTO\RefundRequest;
use Fleetbase\Ledger\Gateways\QPayDriver;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;

final class QPayDriverFake extends QPayDriver
{
    public array $posts           = [];
    public array $deletes         = [];
    public array $postResponses   = [];
    public array $deleteResponses = [];

    protected function post(string $path, array $params): ?object
    {
        $this->posts[] = compact('path', 'params');
        $response      = array_shift($this->postResponses);

        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }

    protected function delete(string $path, array $params = []): ?object
    {
        $this->deletes[] = compact('path', 'params');
        $response        = array_shift($this->deleteResponses);

        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }
}

final class QPayTransportProbe extends QPayDriver
{
    public function useClient(Client $client): void
    {
        $this->client = $client;
    }

    public function sendPost(string $path, array $params): ?object
    {
        return $this->post($path, $params);
    }

    public function sendGet(string $path): ?object
    {
        return $this->get($path);
    }

    public function sendDelete(string $path, array $params): ?object
    {
        return $this->delete($path, $params);
    }
}

function qpayFake(array $postResponses = [], array $deleteResponses = []): QPayDriverFake
{
    $driver = new QPayDriverFake();
    $driver->initialize([
        'username'     => 'merchant-user',
        'password'     => 'merchant-password',
        'invoice_code' => 'LEDGER_INVOICE',
    ], true);
    $driver->postResponses   = $postResponses;
    $driver->deleteResponses = $deleteResponses;

    return $driver;
}

test('qpay metadata describes its provider contract', function () {
    $driver = qpayFake();

    expect($driver->getName())->toBe('QPay')
        ->and($driver->getCode())->toBe('qpay')
        ->and($driver->getCapabilities())->toBe(['purchase', 'refund', 'webhooks', 'sandbox'])
        ->and(array_column($driver->getConfigSchema(), 'key'))
        ->toBe(['username', 'password', 'invoice_code'])
        ->and(QPayDriver::$zeroTaxClassificationCodes)->toContain('2111100', '2119000');
});

test('qpay authenticates once and rejects token responses without an access token', function () {
    $driver = qpayFake([(object) ['access_token' => 'access-token']]);

    $driver->authenticate();
    $driver->authenticate();

    expect($driver->posts)->toHaveCount(1)
        ->and($driver->posts[0])->toBe(['path' => 'auth/token', 'params' => []]);

    expect(fn () => qpayFake([null])->authenticate())
        ->toThrow(RuntimeException::class, 'no access_token');
});

test('qpay purchases create normalized pending invoices and preserve payment links', function () {
    $driver = qpayFake([
        (object) ['access_token' => 'token'],
        (object) [
            'invoice_id' => 'qpay-invoice-1',
            'qr_image'   => 'base64-qr',
            'qr_text'    => 'qr-value',
            'urls'       => [
                (object) ['name' => 'Bank', 'description' => 'Pay in app', 'logo' => 'logo.png', 'link' => 'bank://pay'],
                (object) ['name' => 'Sparse'],
            ],
        ],
    ]);

    $result = $driver->purchase(new PurchaseRequest(
        amount: 12500,
        currency: 'MNT',
        description: 'Ledger invoice',
        invoiceUuid: str_repeat('a', 40),
    ));

    expect($result->isPending())->toBeTrue()
        ->and($result->gatewayTransactionId)->toBe('qpay-invoice-1')
        ->and($result->data['urls'][0]['link'])->toBe('bank://pay')
        ->and($result->data['urls'][1])->toBe([
            'name' => 'Sparse', 'description' => '', 'logo' => '', 'link' => '',
        ])
        ->and($driver->posts[1]['params']['sender_invoice_no'])->toBe(str_repeat('a', 32))
        ->and($driver->posts[1]['params']['callback_url'])
        ->toContain('/ledger/webhooks/qpay?invoice_uuid=' . str_repeat('a', 40));
});

test('qpay purchases honor return urls and normalize missing invoices and exceptions', function () {
    $returned = qpayFake([
        (object) ['access_token' => 'token'],
        (object) ['invoice_id' => 'qpay-return', 'urls' => 'not-an-array'],
    ]);
    $result = $returned->purchase(new PurchaseRequest(
        amount: 100,
        currency: 'MNT',
        description: 'Return URL',
        returnUrl: 'https://merchant.test/return',
    ));

    expect($result->data['urls'])->toBe([])
        ->and($returned->posts[1]['params']['callback_url'])->toBe('https://merchant.test/return')
        ->and($returned->posts[1]['params']['sender_invoice_no'])->toBeString();

    $missing = qpayFake([(object) ['access_token' => 'token'], (object) ['error' => 'invalid']])
        ->purchase(new PurchaseRequest(100, 'MNT', 'Missing'));
    expect($missing->isFailed())->toBeTrue()
        ->and($missing->message)->toBe('QPay invoice creation failed.');

    $exception = qpayFake([new RuntimeException('authentication unavailable')])
        ->purchase(new PurchaseRequest(100, 'MNT', 'Failure'));
    expect($exception->isFailed())->toBeTrue()
        ->and($exception->message)->toBe('authentication unavailable');
});

test('qpay refunds distinguish successful provider responses errors and exceptions', function () {
    $successful = qpayFake([(object) ['access_token' => 'token']], [(object) ['refund_id' => 'refund-1']]);
    $result     = $successful->refund(new RefundRequest('invoice-1', 500, 'MNT'));

    expect($result->status)->toBe(GatewayResponse::STATUS_REFUNDED)
        ->and($result->eventType)->toBe(GatewayResponse::EVENT_REFUND_PROCESSED)
        ->and($successful->deletes[0]['path'])->toBe('payment/refund/invoice-1')
        ->and($successful->deletes[0]['params']['callback_url'])->toContain('/ledger/webhooks/qpay');

    $failed = qpayFake([(object) ['access_token' => 'token']], [(object) ['error' => 'not refundable']])
        ->refund(new RefundRequest('invoice-2', 500, 'MNT'));
    expect($failed->isFailed())->toBeTrue()
        ->and($failed->eventType)->toBe(GatewayResponse::EVENT_REFUND_FAILED);

    $exception = qpayFake([new RuntimeException('auth failed')])
        ->refund(new RefundRequest('invoice-3', 500, 'MNT'));
    expect($exception->message)->toBe('auth failed')
        ->and($exception->rawResponse)->toBe(['error' => 'auth failed']);
});

test('qpay webhooks reject missing references and failed or empty verification', function () {
    $missing = qpayFake()->handleWebhook(Request::create('/webhook', 'POST'));
    expect($missing->eventType)->toBe(GatewayResponse::EVENT_UNKNOWN);

    $failed = qpayFake([(object) ['access_token' => 'token'], null])
        ->handleWebhook(Request::create('/webhook', 'POST', ['payment_id' => 'payment-1']));
    expect($failed->message)->toBe('QPay payment verification failed.')
        ->and($failed->gatewayTransactionId)->toBe('payment-1');

    $empty = qpayFake([(object) ['access_token' => 'token'], (object) ['rows' => []]])
        ->handleWebhook(Request::create('/webhook', 'POST', ['invoice_id' => 'invoice-1']));
    expect($empty->message)->toBe('QPay payment not found.');
});

test('qpay webhooks normalize confirmed and declined provider statuses', function (string $status, bool $successful) {
    $driver = qpayFake([
        (object) ['access_token' => 'token'],
        (object) ['rows' => [(object) ['payment_id' => 'payment-22', 'payment_status' => $status]]],
    ]);
    $result = $driver->handleWebhook(Request::create('/webhook', 'POST', [
        'payment_id'      => 'payment-fallback',
        'qpay_payment_id' => 'invoice-preferred',
    ]));

    expect($result->isSuccessful())->toBe($successful)
        ->and($result->gatewayTransactionId)->toBe('invoice-preferred')
        ->and($result->data['payment_status'])->toBe(strtolower($status))
        ->and($result->data['payment_id'])->toBe('payment-22');
})->with([
    ['PAID', true],
    ['declined', false],
]);

test('qpay webhook exceptions become failed payment responses', function () {
    $result = qpayFake([new RuntimeException('verification offline')])
        ->handleWebhook(Request::create('/webhook', 'POST', ['invoice_id' => 'invoice-9']));

    expect($result->isFailed())->toBeTrue()
        ->and($result->message)->toBe('verification offline')
        ->and($result->rawResponse['invoice_id'])->toBe('invoice-9');
});

test('qpay ebarimt invoices retain tax line and callback contracts', function () {
    $driver = qpayFake([
        (object) ['access_token' => 'token'],
        (object) [
            'invoice_id' => 'ebarimt-1',
            'qr_image'   => 'image',
            'qr_text'    => 'text',
            'urls'       => [(object) ['link' => 'app://pay']],
        ],
    ]);
    $result = $driver->createEbarimtInvoice(
        25000,
        'EBARIMT',
        'sender-1',
        [['tax_product_code' => '2111100', 'line_total_amount' => 25000]],
        ['register' => 'AA12345678'],
        '2',
    );

    expect($result->isPending())->toBeTrue()
        ->and($result->gatewayTransactionId)->toBe('ebarimt-1')
        ->and($driver->posts[1]['params']['tax_type'])->toBe('2')
        ->and($driver->posts[1]['params']['callback_url'])->toContain('/ledger/webhooks/qpay')
        ->and($driver->posts[1]['params']['invoice_receiver_data']['register'])->toBe('AA12345678');

    $missing = qpayFake([(object) ['access_token' => 'token'], null])
        ->createEbarimtInvoice(100, 'CODE', 'sender-2', [], [], '1', 'https://callback.test');
    expect($missing->isFailed())->toBeTrue()
        ->and($missing->message)->toBe('QPay eBarimt invoice creation failed.');

    $exception = qpayFake([new RuntimeException('auth unavailable')])
        ->createEbarimtInvoice(100, 'CODE', 'sender-3', []);
    expect($exception->message)->toBe('auth unavailable');
});

test('qpay payment checks and transport helpers decode responses and contain guzzle failures', function () {
    $driver = qpayFake([(object) ['rows' => []]]);
    expect($driver->checkPaymentStatus('invoice-check'))->toEqual((object) ['rows' => []])
        ->and($driver->posts[0])->toBe([
            'path'   => 'payment/check',
            'params' => ['object_type' => 'INVOICE', 'object_id' => 'invoice-check'],
        ]);

    $failure = new RequestException('network down', new GuzzleRequest('POST', '/'));
    $handler = new MockHandler([
        new Response(200, [], '{"post":"ok"}'),
        new Response(200, [], '{"get":"ok"}'),
        new Response(200, [], '{"delete":"ok"}'),
        $failure,
        $failure,
        $failure,
    ]);
    $probe = new QPayTransportProbe();
    $probe->useClient(new Client(['handler' => HandlerStack::create($handler)]));

    expect($probe->sendPost('/post', ['value' => 1]))->toEqual((object) ['post' => 'ok'])
        ->and($probe->sendGet('/get'))->toEqual((object) ['get' => 'ok'])
        ->and($probe->sendDelete('/delete', ['value' => 2]))->toEqual((object) ['delete' => 'ok'])
        ->and($probe->sendPost('/post-fail', []))->toBeNull()
        ->and($probe->sendGet('/get-fail'))->toBeNull()
        ->and($probe->sendDelete('/delete-fail', []))->toBeNull();
});
