<?php

use Fleetbase\Ledger\DTO\GatewayResponse;
use Fleetbase\Ledger\DTO\PurchaseRequest;
use Fleetbase\Ledger\DTO\RefundRequest;
use Fleetbase\Ledger\Exceptions\WebhookSignatureException;
use Fleetbase\Ledger\Gateways\StripeDriver;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;

class StripeDriverFakeObject
{
    public function __construct(array $attributes)
    {
        foreach ($attributes as $key => $value) {
            $this->{$key} = $value;
        }
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}

class StripeDriverFakeService
{
    public array $calls          = [];
    public mixed $result         = null;
    public ?Throwable $exception = null;

    public function create(array $params): mixed
    {
        $this->calls[] = $params;
        if ($this->exception) {
            throw $this->exception;
        }

        return $this->result;
    }
}

class StripeDriverFakeCheckout
{
    public function __construct(public StripeDriverFakeService $sessions)
    {
    }
}

class StripeDriverFakeClient extends StripeClient
{
    public function __construct(public array $fakeServices)
    {
    }

    public function __get($name)
    {
        return $this->fakeServices[$name];
    }
}

class TestableStripeDriver extends StripeDriver
{
    public function useClient(StripeClient $client): static
    {
        $this->client = $client;

        return $this;
    }
}

function stripeDriverServices(): array
{
    $paymentIntents = new StripeDriverFakeService();
    $refunds        = new StripeDriverFakeService();
    $setupIntents   = new StripeDriverFakeService();
    $sessions       = new StripeDriverFakeService();

    return [
        'paymentIntents' => $paymentIntents,
        'refunds'        => $refunds,
        'setupIntents'   => $setupIntents,
        'sessions'       => $sessions,
        'client'         => new StripeDriverFakeClient([
            'paymentIntents' => $paymentIntents,
            'refunds'        => $refunds,
            'setupIntents'   => $setupIntents,
            'checkout'       => new StripeDriverFakeCheckout($sessions),
        ]),
    ];
}

function stripeDriver(array $config = []): array
{
    $services = stripeDriverServices();
    $driver   = new TestableStripeDriver();
    $driver->initialize(array_merge([
        'publishable_key' => 'pk_test_ledger',
        'webhook_secret'  => null,
    ], $config), true);
    $driver->useClient($services['client']);

    return [$driver, $services];
}

function stripePurchase(array $attributes = []): PurchaseRequest
{
    return new PurchaseRequest(...array_merge([
        'amount'      => 1250,
        'currency'    => 'USD',
        'description' => 'Invoice payment',
        'invoiceUuid' => 'invoice-uuid',
        'orderUuid'   => 'order-uuid',
        'metadata'    => ['tenant' => 'company-1'],
    ], $attributes));
}

function stripeApiException(string $message = 'Stripe rejected request', string $code = 'stripe_error'): InvalidRequestException
{
    return InvalidRequestException::factory($message, 400, null, null, null, $code);
}

function stripeWebhookPayload(string $type, array $object, string $eventId = 'evt_ledger'): string
{
    return json_encode([
        'id'     => $eventId,
        'object' => 'event',
        'type'   => $type,
        'data'   => ['object' => $object],
    ]);
}

test('stripe metadata advertises the supported operational contract', function () {
    $driver = new TestableStripeDriver();
    expect($driver->getCode())->toBe('stripe')
        ->and($driver->getName())->toBe('Stripe')
        ->and($driver->getCapabilities())->toContain(
            'purchase',
            'refund',
            'tokenization',
            'setup_intent',
            'checkout_session',
            'webhooks',
            'sandbox',
            'recurring',
        )
        ->and(array_column($driver->getConfigSchema(), 'key'))
        ->toBe(['publishable_key', 'secret_key', 'webhook_secret']);
});

test('stripe requires initialization before client operations', function () {
    $driver = (new TestableStripeDriver())->initialize(['publishable_key' => 'pk_test'], true);

    expect(fn () => $driver->purchase(stripePurchase()))
        ->toThrow(RuntimeException::class, 'Stripe client is not initialized')
        ->and(fn () => $driver->refund(new RefundRequest('pi_missing', 100, 'USD')))
        ->toThrow(RuntimeException::class)
        ->and(fn () => $driver->createPaymentMethod([]))
        ->toThrow(RuntimeException::class)
        ->and(fn () => $driver->createCheckoutSession(stripePurchase(), 'https://success.test', 'https://cancel.test'))
        ->toThrow(RuntimeException::class);

    expect((new TestableStripeDriver())->initialize(['secret_key' => 'sk_test_ledger'], true))
        ->toBeInstanceOf(TestableStripeDriver::class);
});

test('stripe purchases create pending client flows and confirmed token charges', function () {
    [$driver, $services]                = stripeDriver();
    $services['paymentIntents']->result = new StripeDriverFakeObject([
        'id'            => 'pi_pending',
        'status'        => 'requires_confirmation',
        'amount'        => 1250,
        'currency'      => 'usd',
        'client_secret' => 'pi_secret',
    ]);

    $pending = $driver->purchase(stripePurchase(['customerId' => 'cus_ledger']));
    expect($pending->status)->toBe(GatewayResponse::STATUS_PENDING)
        ->and($pending->eventType)->toBe(GatewayResponse::EVENT_PAYMENT_PENDING)
        ->and($pending->currency)->toBe('USD')
        ->and($pending->data)->toMatchArray([
            'client_secret'     => 'pi_secret',
            'payment_intent_id' => 'pi_pending',
            'publishable_key'   => 'pk_test_ledger',
        ])
        ->and($services['paymentIntents']->calls[0])->toMatchArray([
            'amount'   => 1250,
            'currency' => 'usd',
            'customer' => 'cus_ledger',
            'metadata' => [
                'tenant'       => 'company-1',
                'invoice_uuid' => 'invoice-uuid',
                'order_uuid'   => 'order-uuid',
            ],
        ]);

    $services['paymentIntents']->result = new StripeDriverFakeObject([
        'id'            => 'pi_succeeded',
        'status'        => 'succeeded',
        'amount'        => 900,
        'currency'      => 'mnt',
        'client_secret' => 'secret_succeeded',
    ]);
    $succeeded = $driver->purchase(stripePurchase([
        'amount'             => 900,
        'currency'           => 'MNT',
        'paymentMethodToken' => 'pm_ledger',
        'returnUrl'          => null,
    ]));
    expect($succeeded->status)->toBe(GatewayResponse::STATUS_SUCCEEDED)
        ->and($succeeded->eventType)->toBe(GatewayResponse::EVENT_PAYMENT_SUCCEEDED)
        ->and($services['paymentIntents']->calls[1])->toMatchArray([
            'payment_method'           => 'pm_ledger',
            'confirm'                  => true,
            'return_url'               => 'https://api.example.test/',
            'off_session'              => true,
            'error_on_requires_action' => true,
        ]);
});

test('stripe purchase failures preserve provider messages and codes', function () {
    [$driver, $services]                   = stripeDriver();
    $services['paymentIntents']->exception = stripeApiException('Card declined', 'card_declined');

    $response = $driver->purchase(stripePurchase());
    expect($response->successful)->toBeFalse()
        ->and($response->eventType)->toBe(GatewayResponse::EVENT_PAYMENT_FAILED)
        ->and($response->message)->toBe('Card declined')
        ->and($response->errorCode)->toBe('card_declined');
});

test('stripe refunds distinguish payment intents, charges, provider states, and failures', function () {
    [$driver, $services]         = stripeDriver();
    $services['refunds']->result = new StripeDriverFakeObject([
        'id' => 're_succeeded', 'status' => 'succeeded', 'amount' => 500, 'currency' => 'usd',
    ]);
    $intentRefund = $driver->refund(new RefundRequest(
        'pi_original',
        500,
        'USD',
        'requested_by_customer',
        'invoice-uuid',
        ['reason_note' => 'customer request'],
    ));
    expect($intentRefund->successful)->toBeTrue()
        ->and($intentRefund->status)->toBe(GatewayResponse::STATUS_REFUNDED)
        ->and($services['refunds']->calls[0])->toBe([
            'amount'         => 500,
            'payment_intent' => 'pi_original',
            'reason'         => 'requested_by_customer',
            'metadata'       => ['reason_note' => 'customer request'],
        ]);

    $services['refunds']->result = new StripeDriverFakeObject([
        'id' => 're_pending', 'status' => 'pending', 'amount' => 300, 'currency' => 'mnt',
    ]);
    expect($driver->refund(new RefundRequest('ch_original', 300, 'MNT'))->successful)->toBeTrue()
        ->and($services['refunds']->calls[1])->toBe(['amount' => 300, 'charge' => 'ch_original']);

    $services['refunds']->result = new StripeDriverFakeObject([
        'id' => 're_failed', 'status' => 'failed', 'amount' => 200, 'currency' => 'usd',
    ]);
    $failedState = $driver->refund(new RefundRequest('ch_failed', 200, 'USD'));
    expect($failedState->successful)->toBeFalse()
        ->and($failedState->eventType)->toBe(GatewayResponse::EVENT_REFUND_FAILED);

    $services['refunds']->exception = stripeApiException('Refund rejected', 'refund_invalid');
    $providerFailure                = $driver->refund(new RefundRequest('pi_error', 100, 'USD'));
    expect($providerFailure->successful)->toBeFalse()
        ->and($providerFailure->message)->toBe('Refund rejected')
        ->and($providerFailure->errorCode)->toBe('refund_invalid');
});

test('stripe setup intents and checkout sessions retain frontend and invoice metadata contracts', function () {
    [$driver, $services]              = stripeDriver();
    $services['setupIntents']->result = new StripeDriverFakeObject([
        'id' => 'seti_ledger', 'client_secret' => 'seti_secret',
    ]);
    $setup = $driver->createPaymentMethod(['customer_id' => 'cus_ledger']);
    expect($setup->eventType)->toBe(GatewayResponse::EVENT_SETUP_SUCCEEDED)
        ->and($services['setupIntents']->calls[0])->toBe([
            'customer'             => 'cus_ledger',
            'payment_method_types' => ['card'],
            'usage'                => 'off_session',
        ])
        ->and($setup->data['client_secret'])->toBe('seti_secret');

    $services['setupIntents']->result = new StripeDriverFakeObject([
        'id' => 'seti_no_customer', 'client_secret' => 'seti_no_customer_secret',
    ]);
    $driver->createPaymentMethod([]);
    expect($services['setupIntents']->calls[1])->toBe([
        'payment_method_types' => ['card'],
        'usage'                => 'off_session',
    ]);

    $services['sessions']->result = new StripeDriverFakeObject([
        'id' => 'cs_ledger', 'url' => 'https://checkout.stripe.test/session',
    ]);
    $checkout = $driver->createCheckoutSession(
        stripePurchase(['customerEmail' => 'payer@example.test']),
        'https://success.test',
        'https://cancel.test',
    );
    expect($checkout->status)->toBe(GatewayResponse::STATUS_PENDING)
        ->and($checkout->data['checkout_url'])->toBe('https://checkout.stripe.test/session')
        ->and($services['sessions']->calls[0])->toMatchArray([
            'mode'           => 'payment',
            'success_url'    => 'https://success.test',
            'cancel_url'     => 'https://cancel.test',
            'customer_email' => 'payer@example.test',
            'metadata'       => [
                'tenant'       => 'company-1',
                'invoice_uuid' => 'invoice-uuid',
                'order_uuid'   => 'order-uuid',
            ],
            'payment_intent_data' => [
                'metadata' => [
                    'tenant'       => 'company-1',
                    'invoice_uuid' => 'invoice-uuid',
                    'order_uuid'   => 'order-uuid',
                ],
            ],
        ]);
});

test('stripe setup and checkout API failures map to normalized payment failures', function () {
    [$driver, $services]                 = stripeDriver();
    $services['setupIntents']->exception = stripeApiException('Setup rejected', 'setup_error');
    $services['sessions']->exception     = stripeApiException('Checkout rejected', 'checkout_error');

    $setup    = $driver->createPaymentMethod([]);
    $checkout = $driver->createCheckoutSession(stripePurchase(), 'https://success.test', 'https://cancel.test');
    expect($setup->message)->toBe('Setup rejected')
        ->and($setup->errorCode)->toBe('setup_error')
        ->and($checkout->message)->toBe('Checkout rejected')
        ->and($checkout->errorCode)->toBe('checkout_error');
});

test('stripe webhooks normalize payment, refund, setup, dispute, checkout, and unknown events', function () {
    [$driver] = stripeDriver();
    $cases    = [
        ['payment_intent.succeeded', ['id' => 'pi_success', 'amount' => 100, 'currency' => 'usd'], GatewayResponse::EVENT_PAYMENT_SUCCEEDED, GatewayResponse::STATUS_SUCCEEDED],
        ['payment_intent.payment_failed', ['id' => 'pi_failed', 'amount' => 100, 'currency' => 'usd'], GatewayResponse::EVENT_PAYMENT_FAILED, GatewayResponse::STATUS_FAILED],
        ['payment_intent.created', ['id'        => 'pi_created'], GatewayResponse::EVENT_PAYMENT_PENDING, GatewayResponse::STATUS_PENDING],
        ['payment_intent.processing', ['id'     => 'pi_processing'], GatewayResponse::EVENT_PAYMENT_PENDING, GatewayResponse::STATUS_PENDING],
        ['checkout.session.expired', ['id'      => 'cs_expired'], GatewayResponse::EVENT_PAYMENT_FAILED, GatewayResponse::STATUS_FAILED],
        ['charge.refunded', ['id'               => 'ch_refunded', 'amount' => 50, 'currency' => 'mnt'], GatewayResponse::EVENT_REFUND_PROCESSED, GatewayResponse::STATUS_REFUNDED],
        ['charge.refund.updated', ['id'         => 're_success', 'status' => 'succeeded'], GatewayResponse::EVENT_REFUND_PROCESSED, GatewayResponse::STATUS_REFUNDED],
        ['charge.refund.updated', ['id'         => 're_failed', 'status' => 'failed'], GatewayResponse::EVENT_REFUND_FAILED, GatewayResponse::STATUS_FAILED],
        ['charge.refund.updated', ['id'         => 're_pending', 'status' => 'pending'], GatewayResponse::EVENT_UNKNOWN, GatewayResponse::STATUS_PENDING],
        ['setup_intent.succeeded', ['id'        => 'seti_success'], GatewayResponse::EVENT_SETUP_SUCCEEDED, GatewayResponse::STATUS_SUCCEEDED],
        ['charge.dispute.created', ['id'        => 'dp_created'], GatewayResponse::EVENT_CHARGEBACK, GatewayResponse::STATUS_FAILED],
        ['customer.created', ['id'              => 'cus_created'], GatewayResponse::EVENT_UNKNOWN, GatewayResponse::STATUS_PENDING],
    ];

    foreach ($cases as [$type, $object, $eventType, $status]) {
        $payload  = stripeWebhookPayload($type, $object, 'evt_' . md5($type . json_encode($object)));
        $response = $driver->handleWebhook(Illuminate\Http\Request::create('/stripe/webhook', 'POST', [], [], [], [], $payload));
        expect($response->eventType)->toBe($eventType)
            ->and($response->status)->toBe($status);
    }
});

test('stripe checkout webhooks resolve invoice metadata, payment intent references, and amount fallbacks', function () {
    [$driver] = stripeDriver();
    $payload  = stripeWebhookPayload('checkout.session.completed', [
        'id'             => 'cs_completed',
        'payment_intent' => ['id' => 'pi_checkout'],
        'amount_total'   => 2200,
        'currency'       => 'usd',
        'metadata'       => ['invoice_uuid' => 'invoice-session'],
    ]);
    $response = $driver->handleWebhook(Illuminate\Http\Request::create('/stripe/webhook', 'POST', [], [], [], [], $payload));
    expect($response->successful)->toBeTrue()
        ->and($response->gatewayTransactionId)->toBe('pi_checkout')
        ->and($response->amount)->toBe(2200)
        ->and($response->currency)->toBe('USD')
        ->and($response->data['invoice_uuid'])->toBe('invoice-session');

    $nestedPayload = stripeWebhookPayload('checkout.session.completed', [
        'id'                  => 'cs_nested',
        'payment_intent'      => 'pi_string',
        'payment_intent_data' => ['metadata' => ['invoice_uuid' => 'invoice-nested']],
    ]);
    $nested = $driver->handleWebhook(Illuminate\Http\Request::create('/stripe/webhook', 'POST', [], [], [], [], $nestedPayload));
    expect($nested->gatewayTransactionId)->toBe('pi_string')
        ->and($nested->data['invoice_uuid'])->toBe('invoice-nested');
});

test('stripe webhook signatures accept valid payloads and reject invalid headers', function () {
    $secret   = 'whsec_ledger_test';
    [$driver] = stripeDriver(['webhook_secret' => $secret]);
    $payload  = stripeWebhookPayload('payment_intent.succeeded', [
        'id' => 'pi_signed', 'amount_received' => 777, 'currency' => 'usd',
    ]);
    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    $valid     = Illuminate\Http\Request::create('/stripe/webhook', 'POST', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
    ], $payload);
    expect($driver->handleWebhook($valid)->amount)->toBe(777);

    $invalid = Illuminate\Http\Request::create('/stripe/webhook', 'POST', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1=invalid",
    ], $payload);
    expect(fn () => $driver->handleWebhook($invalid))
        ->toThrow(WebhookSignatureException::class);
});
