<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Ledger\DTO\GatewayResponse;
use Fleetbase\Ledger\DTO\RefundRequest;
use Fleetbase\Ledger\Http\Controllers\Internal\v1\InvoiceController;
use Fleetbase\Ledger\Models\Gateway;
use Fleetbase\Ledger\Models\GatewayTransaction;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Models\InvoiceItem;
use Fleetbase\Ledger\Notifications\RefundUriAvailable;
use Fleetbase\Ledger\Services\InvoiceService;
use Fleetbase\Ledger\Services\PaymentService;
use Fleetbase\Ledger\Services\TalerRefundVerificationService;
use Fleetbase\Models\Template;
use Fleetbase\Services\TemplateRenderService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Notification;

class InvoiceControllerRequest extends Request
{
    public function validate(array $rules, ...$params)
    {
        return $this->all();
    }
}

class InvoiceControllerServiceSpy extends InvoiceService
{
    public array $calls              = [];
    public ?Throwable $sendException = null;

    public function __construct()
    {
    }

    public function recogniseRevenue(Invoice $invoice): void
    {
        $this->calls[] = ['recogniseRevenue', $invoice->uuid, (int) $invoice->total_amount];
    }

    public function autoSendOnCreation(Invoice $invoice): Invoice
    {
        $this->calls[] = ['autoSendOnCreation', $invoice->uuid];

        return $invoice;
    }

    public function recordPayment(Invoice $invoice, int $amount, array $options = []): Invoice
    {
        $this->calls[] = ['recordPayment', $invoice->uuid, $amount, $options];
        $invoice->amount_paid += $amount;
        $invoice->balance -= $amount;
        $invoice->status = $invoice->balance <= 0 ? 'paid' : 'partial';
        $invoice->save();

        return $invoice;
    }

    public function send(Invoice $invoice): Invoice
    {
        $this->calls[] = ['send', $invoice->uuid];

        if ($this->sendException) {
            throw $this->sendException;
        }

        $invoice->markAsSent();

        return $invoice;
    }

    public function createFromOrder(Order $order, array $options = [], ?object $purchaseRate = null): Invoice
    {
        $this->calls[] = ['createFromOrder', $order->uuid];

        return invoiceControllerInvoice([
            'order_uuid' => $order->uuid,
            'status'     => 'draft',
        ]);
    }
}

class InvoiceControllerPaymentService extends PaymentService
{
    public array $calls = [];
    public GatewayResponse $response;

    public function __construct()
    {
    }

    public function refund(string $gatewayIdentifier, RefundRequest $request): GatewayResponse
    {
        $this->calls[] = [$gatewayIdentifier, $request];

        return $this->response;
    }
}

class InvoiceControllerVerifier extends TalerRefundVerificationService
{
    public array $result = [];
    public array $calls  = [];

    public function __construct()
    {
    }

    public function verifyRefund(GatewayTransaction $refund, ?Gateway $gateway = null): array
    {
        $this->calls[] = $refund->uuid;

        return $this->result;
    }
}

class InvoiceControllerTemplateRenderer extends TemplateRenderService
{
    public array $calls = [];

    public function renderToHtml(Template $template, ?Model $subject = null): string
    {
        $this->calls[] = ['html', $template->context_type, $subject?->uuid];

        return '<article>Rendered invoice</article>';
    }

    public function renderToPdf(Template $template, ?Model $subject = null)
    {
        $this->calls[] = ['pdf', $template->context_type, $subject?->uuid];

        return new class {
            public function download(string $filename): Illuminate\Http\Response
            {
                return new Illuminate\Http\Response('PDF', 200, [
                    'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                ]);
            }
        };
    }
}

function invoiceControllerRequest(array $input = []): InvoiceControllerRequest
{
    return InvoiceControllerRequest::create('/ledger/invoices', 'POST', $input);
}

function bootInvoiceControllerDatabase(): void
{
    $capsule    = new Capsule(Container::getInstance());
    $dispatcher = new Dispatcher(Container::getInstance());
    $database   = tempnam(sys_get_temp_dir(), 'ledger-invoice-controller-');
    $capsule->setEventDispatcher($dispatcher);
    Model::setEventDispatcher($dispatcher);
    Model::clearBootedModels();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => $database, 'prefix' => ''], 'testing');
    $capsule->addConnection(['driver' => 'sqlite', 'database' => $database, 'prefix' => ''], 'mysql');
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('testing');
    Container::getInstance()->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstance('db');
    session(['company' => 'company-invoice-controller']);

    $schema = $capsule->getConnection('testing')->getSchemaBuilder();
    $schema->create('ledger_invoices', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('_key')->nullable();
        $table->string('company_uuid');
        $table->string('customer_uuid')->nullable();
        $table->string('customer_type')->nullable();
        $table->string('order_uuid')->nullable();
        $table->string('transaction_uuid')->nullable();
        $table->string('template_uuid')->nullable();
        $table->string('number')->nullable();
        $table->date('date')->nullable();
        $table->date('due_date')->nullable();
        $table->bigInteger('subtotal')->default(0);
        $table->bigInteger('tax')->default(0);
        $table->bigInteger('total_amount')->default(0);
        $table->bigInteger('amount_paid')->default(0);
        $table->bigInteger('balance')->default(0);
        $table->string('currency')->nullable();
        $table->string('status')->default('draft');
        $table->text('notes')->nullable();
        $table->text('terms')->nullable();
        $table->text('meta')->nullable();
        $table->timestamp('sent_at')->nullable();
        $table->timestamp('viewed_at')->nullable();
        $table->timestamp('paid_at')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('ledger_invoice_items', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('invoice_uuid');
        $table->text('description')->nullable();
        $table->integer('quantity')->default(1);
        $table->bigInteger('unit_price')->default(0);
        $table->bigInteger('amount')->default(0);
        $table->decimal('tax_rate', 8, 2)->default(0);
        $table->bigInteger('tax_amount')->default(0);
        $table->text('meta')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('orders', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('_key')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('customer_uuid')->nullable();
        $table->string('customer_type')->nullable();
        $table->text('meta')->nullable();
        $table->string('tracking_number')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    foreach (['customers'] as $tableName) {
        $schema->create($tableName, function (Blueprint $table) {
            $table->string('uuid')->primary();
            $table->string('tracking_number')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }
    $schema->create('templates', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('_key')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('context_type')->nullable();
        $table->text('content')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('ledger_gateways', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('_key')->nullable();
        $table->string('company_uuid');
        $table->string('name')->nullable();
        $table->string('driver');
        $table->text('config')->nullable();
        $table->boolean('is_sandbox')->default(false);
        $table->string('environment')->nullable();
        $table->string('status');
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('ledger_gateway_transactions', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('_key')->nullable();
        $table->string('company_uuid');
        $table->string('gateway_uuid')->nullable();
        $table->string('gateway_reference_id')->nullable();
        $table->string('type');
        $table->string('event_type')->nullable();
        $table->bigInteger('amount')->nullable();
        $table->string('currency')->nullable();
        $table->string('status');
        $table->text('message')->nullable();
        $table->text('raw_response')->nullable();
        $table->timestamp('processed_at')->nullable();
        $table->string('refund_status')->nullable();
        $table->timestamp('refund_accepted_at')->nullable();
        $table->timestamp('refund_expires_at')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('transactions', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid');
        $table->string('context_uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('type');
        $table->string('reference')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
}

function invoiceControllerInvoice(array $attributes = []): Invoice
{
    static $sequence = 0;
    $sequence++;

    return Invoice::withoutEvents(function () use ($attributes, $sequence) {
        $invoice = new Invoice();
        $invoice->forceFill(array_merge([
            'uuid'         => 'invoice-controller-' . $sequence,
            'public_id'    => 'invoice_public_' . $sequence,
            'company_uuid' => 'company-invoice-controller',
            'number'       => 'INV-' . $sequence,
            'date'         => '2026-01-01',
            'subtotal'     => 0,
            'tax'          => 0,
            'total_amount' => 0,
            'amount_paid'  => 0,
            'balance'      => 0,
            'currency'     => 'USD',
            'status'       => 'draft',
            'meta'         => [],
        ], $attributes));
        $invoice->save();

        return $invoice;
    });
}

function invoiceControllerGateway(string $driver = 'taler'): Gateway
{
    return Gateway::withoutEvents(function () use ($driver) {
        $gateway = new Gateway();
        $gateway->forceFill([
            'uuid'         => 'gateway-invoice-controller-' . $driver,
            'public_id'    => 'gateway_public_' . $driver,
            'company_uuid' => 'company-invoice-controller',
            'name'         => ucfirst($driver),
            'driver'       => $driver,
            'is_sandbox'   => true,
            'environment'  => 'sandbox',
            'status'       => 'active',
        ]);
        $gateway->save();

        return $gateway;
    });
}

function invoiceControllerGatewayTransaction(Gateway $gateway, array $attributes = []): GatewayTransaction
{
    static $sequence = 0;
    $sequence++;

    return GatewayTransaction::withoutEvents(function () use ($gateway, $attributes, $sequence) {
        $transaction = new GatewayTransaction();
        $transaction->forceFill(array_merge([
            'uuid'                 => 'invoice-gateway-transaction-' . $sequence,
            'public_id'            => 'gtxn_invoice_' . $sequence,
            'company_uuid'         => $gateway->company_uuid,
            'gateway_uuid'         => $gateway->uuid,
            'gateway_reference_id' => 'provider-payment-' . $sequence,
            'type'                 => 'purchase',
            'event_type'           => GatewayResponse::EVENT_PAYMENT_SUCCEEDED,
            'amount'               => 1000,
            'currency'             => 'USD',
            'status'               => 'succeeded',
            'raw_response'         => [],
        ], $attributes));
        $transaction->save();

        return $transaction;
    });
}

function invoiceControllerTemplate(string $contextType = 'ledger-invoice'): Template
{
    return Template::withoutEvents(function () use ($contextType) {
        $template = new Template();
        $template->forceFill([
            'uuid'         => 'template-invoice-controller-' . str_replace('-', '_', $contextType),
            'public_id'    => 'template_public_' . str_replace('-', '_', $contextType),
            'company_uuid' => 'company-invoice-controller',
            'name'         => 'Invoice Template',
            'context_type' => $contextType,
            'content'      => [],
        ]);
        $template->save();

        return $template;
    });
}

beforeEach(function () {
    bootInvoiceControllerDatabase();
});

test('invoice creation hook synchronizes items totals revenue and automatic delivery', function () {
    $service = new InvoiceControllerServiceSpy();
    Container::getInstance()->instance(InvoiceService::class, $service);
    $invoice    = invoiceControllerInvoice();
    $controller = new InvoiceController();

    $controller->onAfterCreate(invoiceControllerRequest(), $invoice, [
        'items' => [
            ['description' => 'Freight', 'quantity' => 2, 'unit_price' => 400, 'tax_rate' => 10],
            ['description' => 'Handling', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0],
        ],
    ]);
    $invoice->refresh();

    expect($invoice->items)->toHaveCount(2)
        ->and($invoice->subtotal)->toBe(900)
        ->and($invoice->tax)->toBe(80)
        ->and($invoice->total_amount)->toBe(980)
        ->and($invoice->balance)->toBe(980)
        ->and($service->calls)->toBe([
            ['recogniseRevenue', $invoice->uuid, 980],
            ['autoSendOnCreation', $invoice->uuid],
        ]);
});

test('invoice update hook updates creates and removes nested items deterministically', function () {
    $invoice  = invoiceControllerInvoice();
    $existing = new InvoiceItem([
        'invoice_uuid' => $invoice->uuid,
        'description'  => 'Old freight',
        'quantity'     => 1,
        'unit_price'   => 100,
        'tax_rate'     => 0,
    ]);
    $existing->calculateAmount();
    $existing->save();
    $removed = new InvoiceItem([
        'invoice_uuid' => $invoice->uuid,
        'description'  => 'Remove me',
        'quantity'     => 1,
        'unit_price'   => 50,
        'tax_rate'     => 0,
    ]);
    $removed->calculateAmount();
    $removed->save();

    (new InvoiceController())->onAfterUpdate(invoiceControllerRequest(), $invoice, [
        'items' => [
            [
                'uuid'        => $existing->uuid,
                'description' => 'Updated freight',
                'quantity'    => 3,
                'unit_price'  => 200,
                'tax_rate'    => 5,
            ],
            [
                'uuid'        => '_new_browser-id',
                'description' => 'New surcharge',
                'quantity'    => 1,
                'unit_price'  => 75,
            ],
            [
                'uuid'        => '_tmp_editor-id',
                'description' => 'New handling',
                'quantity'    => 2,
                'unit_price'  => 25,
            ],
        ],
    ]);
    $invoice->refresh();
    $items = $invoice->items()->orderBy('description')->get();

    expect($items)->toHaveCount(3)
        ->and($items->pluck('description')->all())->toBe(['New handling', 'New surcharge', 'Updated freight'])
        ->and($items->firstWhere('description', 'Updated freight')->amount)->toBe(600)
        ->and($items->firstWhere('description', 'Updated freight')->tax_amount)->toBe(30)
        ->and(InvoiceItem::withTrashed()->find($removed->uuid)->trashed())->toBeTrue()
        ->and($invoice->total_amount)->toBe(755);
});

test('invoice item synchronization rejects missing descriptions and ignores non-array payloads', function () {
    $invoice    = invoiceControllerInvoice();
    $controller = new InvoiceController();

    expect(fn () => $controller->onAfterUpdate(invoiceControllerRequest(), $invoice, [
        'items' => [['description' => '']],
    ]))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class, 'Line item 1');

    $controller->onAfterUpdate(invoiceControllerRequest(), $invoice, ['items' => 'invalid']);
    expect($invoice->items()->count())->toBe(0);
});

test('recording and marking invoice payments honor tenant lookup and response state', function () {
    $service = new InvoiceControllerServiceSpy();
    Container::getInstance()->instance(InvoiceService::class, $service);
    Container::getInstance()->instance('request', Request::create('/internal'));
    $invoice = invoiceControllerInvoice([
        'status'       => 'sent',
        'total_amount' => 1000,
        'amount_paid'  => 0,
        'balance'      => 1000,
    ]);
    $controller = new InvoiceController();

    $resource = $controller->recordPayment($invoice->public_id, invoiceControllerRequest([
        'amount'         => 400,
        'payment_method' => 'bank',
        'reference'      => 'BANK-1',
    ]));
    $resolved = $resource->resolve();

    expect($resolved['status'])->toBe('partial')
        ->and($service->calls[0])->toBe([
            'recordPayment',
            $invoice->uuid,
            400,
            ['payment_method' => 'bank', 'reference' => 'BANK-1'],
        ]);

    $marked = $controller->markAsSent($invoice->uuid, invoiceControllerRequest());
    expect($marked->resource->status)->toBe('sent')
        ->and($marked->resource->sent_at)->not->toBeNull();
});

test('invoice sending maps service validation failures and successful delivery', function () {
    $service = new InvoiceControllerServiceSpy();
    Container::getInstance()->instance(InvoiceService::class, $service);
    Container::getInstance()->instance('request', Request::create('/internal'));
    $invoice    = invoiceControllerInvoice(['status' => 'draft']);
    $controller = new InvoiceController();

    $service->sendException = new InvalidArgumentException('Customer email is missing.');
    $failed                 = $controller->send($invoice->uuid, invoiceControllerRequest());
    expect($failed->getStatusCode())->toBe(422)
        ->and($failed->getData(true)['error'])->toBe('Customer email is missing.');

    $service->sendException = null;
    $sent                   = $controller->send($invoice->public_id, invoiceControllerRequest());
    expect($sent->resource->status)->toBe('sent');
});

test('refund options aggregate payment references prior refunds and Taler customer action', function () {
    $gateway = invoiceControllerGateway();
    $invoice = invoiceControllerInvoice([
        'status'       => 'paid',
        'total_amount' => 1000,
        'amount_paid'  => 1000,
        'balance'      => 0,
        'meta'         => [
            'refunded_amount'                      => 200,
            'last_refund_gateway_transaction_uuid' => 'gateway-refund',
        ],
    ]);
    Capsule::table('transactions')->insert([
        'uuid'         => 'core-invoice-payment',
        'public_id'    => 'transaction_payment',
        'company_uuid' => $invoice->company_uuid,
        'context_uuid' => $invoice->uuid,
        'type'         => 'invoice_payment',
        'reference'    => 'provider-payment',
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    Capsule::table('transactions')->insert([
        'uuid'         => 'core-refund-transaction',
        'public_id'    => 'transaction_refund',
        'company_uuid' => $invoice->company_uuid,
        'context_uuid' => $invoice->uuid,
        'type'         => 'gateway_refund',
        'reference'    => 'core-linked-refund',
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    invoiceControllerGatewayTransaction($gateway, [
        'uuid'                 => 'gateway-purchase',
        'gateway_reference_id' => 'provider-payment',
        'amount'               => 1000,
        'raw_response'         => ['invoice_uuid' => $invoice->uuid],
    ]);
    invoiceControllerGatewayTransaction($gateway, [
        'uuid'                 => 'gateway-purchase-duplicate',
        'gateway_reference_id' => 'provider-payment',
        'type'                 => 'webhook_event',
        'amount'               => 1000,
        'raw_response'         => ['data' => ['invoice_uuid' => $invoice->uuid]],
    ]);
    invoiceControllerGatewayTransaction($gateway, [
        'uuid'                 => 'gateway-refund',
        'gateway_reference_id' => 'refund-reference',
        'type'                 => 'refund',
        'amount'               => 300,
        'refund_status'        => 'pending_wallet_acceptance',
        'raw_response'         => [
            'metadata'                      => ['invoice_uuid' => $invoice->uuid],
            'original_gateway_reference_id' => 'provider-payment',
            'data'                          => [
                'wallet_status'    => 'pending',
                'taler_refund_uri' => 'taler://refund/demo',
            ],
        ],
    ]);
    invoiceControllerGatewayTransaction($gateway, [
        'uuid'                 => 'core-linked-refund',
        'gateway_reference_id' => 'unrelated-provider-reference',
        'type'                 => 'refund',
        'amount'               => 25,
        'raw_response'         => [],
    ]);
    Container::getInstance()->instance('request', Request::create('/internal'));

    $data = (new InvoiceController())->refundOptions($invoice->public_id, invoiceControllerRequest())->getData(true);

    expect($data['invoice'])->toMatchArray([
        'refunded_amount'             => 200,
        'remaining_refundable_amount' => 800,
    ])->and($data['options'])->toHaveCount(1)
        ->and($data['options'][0])->toMatchArray([
            'gateway_transaction_id'   => 'provider-payment',
            'amount'                   => 1000,
            'refunded_amount'          => 300,
            'refundable_amount'        => 700,
            'requires_customer_action' => true,
        ])->and($data['refunds'])->toHaveCount(2)
        ->and(collect($data['refunds'])->firstWhere('uuid', 'gateway-refund')['taler_refund_uri'])
        ->toBe('taler://refund/demo')
        ->and(collect($data['refunds'])->firstWhere('uuid', 'gateway-refund')['refund_handoff_url'])
        ->toContain('taler-refund');
});

test('refund options reject terminal invoices exhausted balances and exhausted gateway payments', function () {
    $gateway    = invoiceControllerGateway('cash');
    $controller = new InvoiceController();

    foreach (['draft', 'void', 'cancelled', 'refunded'] as $status) {
        $invoice = invoiceControllerInvoice([
            'status'       => $status,
            'amount_paid'  => 1000,
            'total_amount' => 1000,
            'meta'         => [],
        ]);
        expect($controller->refundOptions($invoice->uuid, invoiceControllerRequest())->getData(true)['options'])->toBe([]);
    }

    $exhausted = invoiceControllerInvoice([
        'status'       => 'paid',
        'amount_paid'  => 500,
        'total_amount' => 500,
        'meta'         => ['refunded_amount' => 500],
    ]);
    expect($controller->refundOptions($exhausted->uuid, invoiceControllerRequest())->getData(true)['options'])->toBe([]);

    $invoice = invoiceControllerInvoice([
        'status'       => 'paid',
        'amount_paid'  => 500,
        'total_amount' => 500,
        'meta'         => [],
    ]);
    invoiceControllerGatewayTransaction($gateway, [
        'gateway_reference_id' => 'cash-payment',
        'amount'               => 500,
        'raw_response'         => ['invoice_uuid' => $invoice->uuid],
    ]);
    invoiceControllerGatewayTransaction($gateway, [
        'gateway_reference_id' => 'cash-payment',
        'type'                 => 'refund',
        'amount'               => 500,
        'raw_response'         => [
            'invoice_uuid'                  => $invoice->uuid,
            'original_gateway_reference_id' => 'cash-payment',
        ],
    ]);
    expect($controller->refundOptions($invoice->uuid, invoiceControllerRequest())->getData(true)['options'])->toBe([]);
});

test('invoice refunds enforce selected payment and maximum amount contracts', function () {
    $gateway = invoiceControllerGateway('cash');
    $invoice = invoiceControllerInvoice([
        'status'       => 'paid',
        'total_amount' => 1000,
        'amount_paid'  => 1000,
        'balance'      => 0,
        'meta'         => ['refunded_amount' => 200],
    ]);
    invoiceControllerGatewayTransaction($gateway, [
        'gateway_reference_id' => 'cash-payment',
        'amount'               => 600,
        'raw_response'         => ['invoice_uuid' => $invoice->uuid],
    ]);
    $payment           = new InvoiceControllerPaymentService();
    $payment->response = GatewayResponse::success(
        'refund-provider',
        GatewayResponse::EVENT_REFUND_PROCESSED,
        'Refunded',
        400,
        'USD',
        data: ['receipt' => 'refund-receipt'],
    );
    $controller = new InvoiceController();

    $invalid = $controller->refund($invoice->uuid, invoiceControllerRequest([
        'gateway_transaction_id' => 'unknown-payment',
        'amount'                 => 100,
    ]), $payment);
    expect($invalid->getStatusCode())->toBe(422)
        ->and($invalid->getData(true)['error'])->toContain('cannot be refunded');

    $excessive = $controller->refund($invoice->uuid, invoiceControllerRequest([
        'gateway_transaction_id' => 'cash-payment',
        'amount'                 => 601,
    ]), $payment);
    expect($excessive->getStatusCode())->toBe(422)
        ->and($excessive->getData(true)['remaining_refundable_amount'])->toBe(600);
});

test('invoice refunds classify partial and full requests and map provider responses', function () {
    $gateway = invoiceControllerGateway('cash');
    $invoice = invoiceControllerInvoice([
        'status'       => 'paid',
        'total_amount' => 1000,
        'amount_paid'  => 1000,
        'balance'      => 0,
        'meta'         => ['refunded_amount' => 200],
    ]);
    invoiceControllerGatewayTransaction($gateway, [
        'gateway_reference_id' => 'cash-payment',
        'amount'               => 1000,
        'raw_response'         => ['invoice_uuid' => $invoice->uuid],
    ]);
    $payment           = new InvoiceControllerPaymentService();
    $payment->response = GatewayResponse::success(
        'refund-partial',
        GatewayResponse::EVENT_REFUND_PROCESSED,
        'Refund approved',
        300,
        'USD',
        data: ['provider' => 'cash'],
    );
    Container::getInstance()->instance('request', Request::create('/internal'));
    $controller = new InvoiceController();

    $partial = $controller->refund($invoice->uuid, invoiceControllerRequest([
        'gateway_transaction_id' => 'cash-payment',
        'amount'                 => 300,
        'reason'                 => 'Partial service failure',
    ]), $payment);
    [, $request] = $payment->calls[0];

    expect($partial->getStatusCode())->toBe(200)
        ->and($partial->getData(true)['refund_kind'])->toBe('partial')
        ->and($request->gatewayTransactionId)->toBe('cash-payment')
        ->and($request->invoiceUuid)->toBe($invoice->uuid)
        ->and($request->metadata)->toMatchArray([
            'refund_kind'  => 'partial',
            'invoice_uuid' => $invoice->uuid,
        ]);

    $payment->response = GatewayResponse::failure(
        'refund-full',
        GatewayResponse::EVENT_REFUND_FAILED,
        'Provider rejected refund',
    );
    $full = $controller->refund($invoice->uuid, invoiceControllerRequest([
        'gateway_transaction_id' => 'cash-payment',
        'amount'                 => 800,
    ]), $payment);
    expect($full->getStatusCode())->toBe(422)
        ->and($full->getData(true)['refund_kind'])->toBe('full');
});

test('refund URI delivery validates URI and customer email fallbacks before notifying', function () {
    Notification::fake();
    $notificationFake = Notification::getFacadeRoot();
    Container::getInstance()->instance(Illuminate\Contracts\Notifications\Dispatcher::class, $notificationFake);
    $gateway = invoiceControllerGateway();
    Capsule::table('customers')->insert([
        'uuid'       => 'customer-refund-email',
        'name'       => 'Refund Customer',
        'email'      => 'customer@example.test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $invoice = invoiceControllerInvoice([
        'customer_uuid' => 'customer-refund-email',
        'customer_type' => Fleetbase\Models\Customer::class,
        'status'        => 'refund_pending',
        'amount_paid'   => 1000,
        'total_amount'  => 1000,
    ]);
    $withoutUri = invoiceControllerGatewayTransaction($gateway, [
        'type'         => 'refund',
        'raw_response' => ['invoice_uuid' => $invoice->uuid],
    ]);
    $controller = new InvoiceController();

    $missing = $controller->sendRefundUri(
        $invoice->uuid,
        $withoutUri->uuid,
        invoiceControllerRequest(),
    );
    expect($missing->getStatusCode())->toBe(422)
        ->and($missing->getData(true)['error'])->toContain('does not have');

    $refund = invoiceControllerGatewayTransaction($gateway, [
        'type'          => 'refund',
        'refund_status' => 'pending_wallet_acceptance',
        'raw_response'  => [
            'data' => [
                'invoice_uuid' => $invoice->uuid,
                'refund_url'   => 'taler://refund/customer',
            ],
        ],
    ]);
    $sent = $controller->sendRefundUri($invoice->public_id, $refund->public_id, invoiceControllerRequest());

    expect($sent->getStatusCode())->toBe(200)
        ->and($sent->getData(true)['sent_to'])->toBe('customer@example.test')
        ->and($sent->getData(true)['refund']['taler_refund_uri'])->toBe('taler://refund/customer');
    Notification::assertSentOnDemand(RefundUriAvailable::class);

    $override = $controller->sendRefundUri($invoice->uuid, $refund->gateway_reference_id, invoiceControllerRequest([
        'email' => 'override@example.test',
    ]));
    expect($override->getData(true)['sent_to'])->toBe('override@example.test');
});

test('refund URI delivery reports invoices without a reachable customer address', function () {
    $gateway = invoiceControllerGateway();
    $invoice = invoiceControllerInvoice(['status' => 'refund_pending']);
    $refund  = invoiceControllerGatewayTransaction($gateway, [
        'type'         => 'refund',
        'raw_response' => [
            'metadata'         => ['invoice_uuid' => $invoice->uuid],
            'taler_refund_uri' => 'taler://refund/no-email',
        ],
    ]);

    $response = (new InvoiceController())->sendRefundUri(
        $invoice->uuid,
        $refund->uuid,
        invoiceControllerRequest(),
    );

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['error'])->toContain('valid email');
});

test('manual refund verification maps accepted and error results with refreshed resources', function () {
    $gateway = invoiceControllerGateway();
    $invoice = invoiceControllerInvoice([
        'status'       => 'refund_pending',
        'amount_paid'  => 1000,
        'total_amount' => 1000,
    ]);
    $refund = invoiceControllerGatewayTransaction($gateway, [
        'type'         => 'refund',
        'raw_response' => [
            'invoice_uuid' => $invoice->uuid,
            'refund_url'   => 'taler://refund/verify',
        ],
    ]);
    Container::getInstance()->instance('request', Request::create('/internal'));
    $verifier         = new InvoiceControllerVerifier();
    $verifier->result = ['status' => 'accepted', 'message' => 'Wallet accepted refund'];
    $controller       = new InvoiceController();

    $accepted = $controller->verifyRefundStatus($invoice->uuid, $refund->public_id, $verifier);
    expect($accepted->getStatusCode())->toBe(200)
        ->and($accepted->getData(true)['ok'])->toBeTrue()
        ->and($accepted->getData(true)['result']['status'])->toBe('accepted')
        ->and($verifier->calls)->toBe([$refund->uuid]);

    $verifier->result = ['status' => 'error', 'message' => 'Merchant unavailable'];
    $error            = $controller->verifyRefundStatus($invoice->uuid, $refund->uuid, $verifier);
    expect($error->getStatusCode())->toBe(422)
        ->and($error->getData(true)['ok'])->toBeFalse();
});

test('invoice preview normalizes legacy template contexts without persisting changes', function () {
    $template = invoiceControllerTemplate('ledger-invoice');
    $invoice  = invoiceControllerInvoice(['template_uuid' => $template->uuid]);
    $renderer = new InvoiceControllerTemplateRenderer();
    Container::getInstance()->instance(TemplateRenderService::class, $renderer);
    $controller = new InvoiceController();

    $response = $controller->preview($invoice->uuid, invoiceControllerRequest());

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['html'])->toBe('<article>Rendered invoice</article>')
        ->and($renderer->calls[0])->toBe(['html', 'invoice', $invoice->uuid])
        ->and($template->fresh()->context_type)->toBe('ledger-invoice');

    $canonical        = invoiceControllerTemplate('invoice');
    $canonicalInvoice = invoiceControllerInvoice(['template_uuid' => $canonical->uuid]);
    $controller->preview($canonicalInvoice->uuid, invoiceControllerRequest());
    expect($renderer->calls[1])->toBe(['html', 'invoice', $canonicalInvoice->uuid]);
});

test('invoice preview and PDF rendering reject missing templates and honor filenames', function () {
    $invoice    = invoiceControllerInvoice(['number' => 'INV-PDF']);
    $controller = new InvoiceController();

    expect($controller->preview($invoice->uuid, invoiceControllerRequest())->getStatusCode())->toBe(422);
    expect(fn () => $controller->renderPdf($invoice->uuid, invoiceControllerRequest()))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class, 'no template');

    $template               = invoiceControllerTemplate();
    $invoice->template_uuid = $template->uuid;
    $invoice->save();
    $renderer = new InvoiceControllerTemplateRenderer();
    Container::getInstance()->instance(TemplateRenderService::class, $renderer);

    $default = $controller->renderPdf($invoice->uuid, invoiceControllerRequest());
    expect($default->headers->get('Content-Disposition'))->toContain('invoice-INV-PDF.pdf');

    $custom = $controller->renderPdf($invoice->uuid, invoiceControllerRequest(['filename' => 'custom-refund-invoice']));
    expect($custom->headers->get('Content-Disposition'))->toContain('custom-refund-invoice.pdf')
        ->and($renderer->calls)->toHaveCount(2);
});

test('order conversion is tenant scoped and delegates invoice construction', function () {
    Capsule::table('orders')->insert([
        'uuid'          => 'order-for-invoice',
        'public_id'     => 'order_public',
        '_key'          => 'console',
        'company_uuid'  => 'company-invoice-controller',
        'customer_uuid' => null,
        'customer_type' => null,
        'meta'          => json_encode([]),
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);
    $service = new InvoiceControllerServiceSpy();
    Container::getInstance()->instance(InvoiceService::class, $service);
    Container::getInstance()->instance('request', Request::create('/internal'));

    $resource = (new InvoiceController())->createFromOrder(invoiceControllerRequest([
        'order_uuid' => 'order-for-invoice',
    ]));

    expect($resource->resource->order_uuid)->toBe('order-for-invoice')
        ->and($service->calls)->toBe([['createFromOrder', 'order-for-invoice']]);
});

test('invoice transaction listing builds both direct and contextual relationship queries', function () {
    $controller    = new InvoiceController();
    $withoutDirect = invoiceControllerInvoice(['transaction_uuid' => null]);
    $request       = invoiceControllerRequest(['sort' => 'created_at', 'limit' => 10]);
    Container::getInstance()->instance('request', $request);

    $ascending = $controller->transactions($withoutDirect->uuid, $request);
    expect($ascending->resource)->toHaveCount(0);

    $withDirect        = invoiceControllerInvoice(['transaction_uuid' => 'direct-transaction']);
    $descendingRequest = invoiceControllerRequest(['sort' => '-created_at']);
    Container::getInstance()->instance('request', $descendingRequest);
    $descending = $controller->transactions($withDirect->public_id, $descendingRequest);
    expect($descending->resource)->toHaveCount(0);
});
