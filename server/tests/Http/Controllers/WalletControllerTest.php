<?php

use Fleetbase\Ledger\DTO\GatewayResponse;
use Fleetbase\Ledger\Http\Controllers\Api\v1\WalletApiController;
use Fleetbase\Ledger\Http\Controllers\Internal\v1\WalletController;
use Fleetbase\Ledger\Http\Resources\v1\Transaction as TransactionResource;
use Fleetbase\Ledger\Models\Transaction;
use Fleetbase\Ledger\Models\Wallet;
use Fleetbase\Ledger\Services\WalletService;
use Fleetbase\Models\Company;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;

class WalletControllerRequest extends Request
{
    public function validate(array $rules, ...$params)
    {
        return $this->all();
    }
}

class WalletControllerService extends WalletService
{
    public array $calls               = [];
    public ?Throwable $topUpException = null;
    public ?Transaction $transaction  = null;
    public ?Wallet $wallet            = null;
    public GatewayResponse $gatewayResponse;

    public function __construct()
    {
        $this->gatewayResponse = GatewayResponse::pending('gateway-pending', data: ['payment_url' => 'https://pay.test']);
    }

    public function getOrCreateWallet(Model $subject, string $currency = 'USD'): Wallet
    {
        $this->calls[] = ['getOrCreateWallet', $subject->getAttribute('uuid'), $currency];

        return $this->wallet ??= walletControllerWallet();
    }

    public function transfer(
        Wallet $from,
        Wallet $to,
        int $amount,
        string $description = 'Wallet transfer',
        array $options = [],
    ): array {
        $this->calls[] = ['transfer', $from->uuid, $to->uuid, $amount, $description];

        return [
            'from' => walletControllerTransaction($from, ['uuid' => 'transfer-from', 'direction' => 'debit']),
            'to'   => walletControllerTransaction($to, ['uuid' => 'transfer-to', 'direction' => 'credit']),
        ];
    }

    public function deposit(
        Wallet $wallet,
        int $amount,
        string $description = 'Deposit',
        string $type = 'deposit',
        array $options = [],
    ): Transaction {
        $this->calls[] = ['deposit', $wallet->uuid, $amount, $description, $type];

        return walletControllerTransaction($wallet, ['uuid' => 'deposit-transaction', 'amount' => $amount]);
    }

    public function topUp(
        Wallet $wallet,
        int $amount,
        string $gatewayUuid,
        array $paymentData = [],
        string $description = 'Wallet top-up',
    ): array {
        $this->calls[] = ['topUp', $wallet->uuid, $amount, $gatewayUuid, $paymentData, $description];

        if ($this->topUpException) {
            throw $this->topUpException;
        }

        return [
            'wallet'           => $wallet,
            'transaction'      => $this->transaction,
            'gateway_response' => $this->gatewayResponse,
        ];
    }

    public function withdraw(
        Wallet $wallet,
        int $amount,
        string $description = 'Withdrawal',
        string $type = 'withdrawal',
        array $options = [],
    ): Transaction {
        $this->calls[] = ['withdraw', $wallet->uuid, $amount, $description, $type, $options];

        return walletControllerTransaction($wallet, ['uuid' => 'payout-transaction', 'amount' => $amount, 'direction' => 'debit']);
    }

    public function recalculateBalance(Wallet $wallet): int
    {
        $this->calls[] = ['recalculateBalance', $wallet->uuid];
        $wallet->update(['balance' => 4321]);

        return 4321;
    }
}

function walletControllerRequest(array $input = []): WalletControllerRequest
{
    $request = WalletControllerRequest::create('/ledger/wallets', 'POST', $input);
    Container::getInstance()->instance('request', $request);

    return $request;
}

function bootWalletControllerDatabase(): void
{
    $capsule    = new Capsule(Container::getInstance());
    $dispatcher = new Dispatcher(Container::getInstance());
    $capsule->setEventDispatcher($dispatcher);
    Model::setEventDispatcher($dispatcher);
    Model::clearBootedModels();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'testing');
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    // Fleetbase\Models\User pins `protected $connection = 'mysql'`, so resolveSubject's
    // session fallback queries that name specifically. Its own in-memory database is
    // enough — nothing joins users to the ledger tables.
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'mysql');
    $capsule->getDatabaseManager()->setDefaultConnection('testing');
    Container::getInstance()->instance('db', $capsule->getDatabaseManager());
    Container::getInstance()->instance('request', new Request());
    Facade::clearResolvedInstance('db');
    session(['company' => 'company-wallet-controller']);

    $schema = $capsule->getConnection('testing')->getSchemaBuilder();
    // resolveSubject() falls back to session('user') when no user resolver is bound,
    // which is the case on every public API request.
    $capsule->getConnection('mysql')->getSchemaBuilder()->create('users', function (Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('type')->nullable();
        $table->string('status')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('ledger_wallets', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('_key')->nullable();
        $table->string('company_uuid');
        $table->string('created_by_uuid')->nullable();
        $table->string('updated_by_uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->bigInteger('balance')->default(0);
        $table->string('currency')->default('USD');
        $table->string('status')->default('active');
        $table->text('meta')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('transactions', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('owner_type')->nullable();
        $table->string('type')->nullable();
        $table->string('direction')->nullable();
        $table->string('status')->nullable();
        $table->string('settlement_status')->nullable();
        $table->bigInteger('amount')->default(0);
        $table->bigInteger('balance_after')->nullable();
        $table->string('currency')->nullable();
        $table->text('description')->nullable();
        $table->string('reference')->nullable();
        $table->text('meta')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
}

function walletControllerWallet(array $attributes = []): Wallet
{
    static $sequence = 0;
    $sequence++;
    $uuid = $attributes['uuid'] ?? 'wallet-controller-' . $sequence;

    $existing = Wallet::query()->find($uuid);
    if ($existing) {
        return $existing;
    }

    return Wallet::withoutEvents(function () use ($uuid, $sequence, $attributes) {
        $wallet = new Wallet();
        $wallet->forceFill(array_merge([
            'uuid'         => $uuid,
            'public_id'    => 'wallet_public_' . $sequence,
            'company_uuid' => 'company-wallet-controller',
            'subject_uuid' => 'subject-wallet-controller',
            'subject_type' => Company::class,
            'name'         => 'Operating wallet',
            'balance'      => 2500,
            'currency'     => 'USD',
            'status'       => 'active',
        ], $attributes));
        $wallet->save();

        return $wallet;
    });
}

function walletControllerTransaction(Wallet $wallet, array $attributes = []): Transaction
{
    static $sequence = 0;
    $sequence++;
    $uuid = $attributes['uuid'] ?? 'wallet-transaction-' . $sequence;

    $existing = Transaction::query()->find($uuid);
    if ($existing) {
        return $existing;
    }

    return Transaction::withoutEvents(function () use ($wallet, $uuid, $sequence, $attributes) {
        $transaction = new Transaction();
        $transaction->forceFill(array_merge([
            'uuid'         => $uuid,
            'public_id'    => 'transaction_public_' . $sequence,
            'company_uuid' => $wallet->company_uuid,
            'owner_uuid'   => $wallet->uuid,
            'owner_type'   => Wallet::class,
            'type'         => 'deposit',
            'direction'    => 'credit',
            'status'       => 'completed',
            'amount'       => 500,
            'currency'     => 'USD',
            'description'  => 'Wallet operation',
            'created_at'   => '2026-07-26 12:00:00',
            'updated_at'   => '2026-07-26 12:00:00',
        ], $attributes));
        $transaction->save();

        return $transaction;
    });
}

function walletControllerJson(mixed $response): array
{
    return json_decode($response->getContent(), true);
}

beforeEach(function () {
    bootWalletControllerDatabase();
});

test('internal wallet operations resolve tenant wallets and delegate complete financial contracts', function () {
    $service    = new WalletControllerService();
    $controller = new WalletController($service);
    $from       = walletControllerWallet(['uuid' => 'from-wallet', 'public_id' => 'from_public']);
    $to         = walletControllerWallet(['uuid' => 'to-wallet', 'public_id' => 'to_public']);

    $transfer = walletControllerJson($controller->transfer('from_public', walletControllerRequest([
        'to_wallet_uuid' => 'to-wallet',
        'amount'         => 700,
        'description'    => 'Settlement transfer',
    ])));
    expect($transfer)->toHaveKeys(['from_wallet', 'to_wallet', 'transaction', 'to_transaction'])
        ->and($service->calls[0])->toMatchArray(['transfer', $from->uuid, $to->uuid, 700, 'Settlement transfer']);

    $credit = walletControllerJson($controller->credit($from->uuid, walletControllerRequest(['amount' => 300])));
    expect($credit)->toHaveKeys(['wallet', 'transaction'])
        ->and($service->calls[1])->toBe(['deposit', $from->uuid, 300, 'Manual credit', 'deposit']);

    $payout = walletControllerJson($controller->payout($from->uuid, walletControllerRequest([
        'amount'      => 200,
        'description' => 'Driver earnings',
        'reference'   => 'PAYOUT-1',
    ])));
    expect($payout)->toHaveKeys(['wallet', 'transaction'])
        ->and($service->calls[2])->toBe([
            'withdraw', $from->uuid, 200, 'Driver earnings', 'payout', ['reference' => 'PAYOUT-1'],
        ]);
});

test('internal wallet top ups preserve provider input and conditionally expose transactions', function () {
    $service    = new WalletControllerService();
    $controller = new WalletController($service);
    $wallet     = walletControllerWallet(['uuid' => 'topup-wallet']);

    $pending = walletControllerJson($controller->topUp($wallet->uuid, walletControllerRequest([
        'amount'               => 1200,
        'gateway_uuid'         => 'gateway-uuid',
        'payment_method_token' => 'pm_test',
        'customer_email'       => 'payer@example.test',
        'customer_id'          => 'customer-1',
        'metadata'             => ['invoice' => 'invoice-1'],
    ])));
    expect($pending['status'])->toBe('pending')
        ->and($pending)->not->toHaveKey('transaction')
        ->and($service->calls[0][4])->toBe([
            'payment_method_token' => 'pm_test',
            'customer_email'       => 'payer@example.test',
            'customer_id'          => 'customer-1',
            'metadata'             => ['invoice' => 'invoice-1'],
        ]);

    $service->transaction = walletControllerTransaction($wallet);
    $completed            = walletControllerJson($controller->topUp($wallet->uuid, walletControllerRequest([
        'amount'               => 1200,
        'gateway_uuid'         => 'gateway-uuid',
        'payment_method_token' => 'pm_test',
        'description'          => 'Account funding',
    ])));
    expect($completed)->toHaveKey('transaction')
        ->and($service->calls[1][5])->toBe('Account funding');
});

test('internal wallet history applies every filter and state management updates persisted wallets', function () {
    $service    = new WalletControllerService();
    $controller = new WalletController($service);
    $wallet     = walletControllerWallet(['uuid' => 'history-wallet', 'balance' => 2500]);
    walletControllerTransaction($wallet, [
        'uuid'       => 'matching-transaction',
        'type'       => 'payout',
        'direction'  => 'debit',
        'status'     => 'completed',
        'created_at' => '2026-07-20 12:00:00',
    ]);
    walletControllerTransaction($wallet, [
        'uuid'       => 'excluded-transaction',
        'type'       => 'deposit',
        'direction'  => 'credit',
        'status'     => 'pending',
        'created_at' => '2026-07-10 12:00:00',
    ]);

    $collection = $controller->getTransactions($wallet->public_id, Request::create('/wallet/transactions', 'GET', [
        'type'      => 'payout',
        'direction' => 'debit',
        'status'    => 'completed',
        'date_from' => '2026-07-15',
        'date_to'   => '2026-07-25',
        'limit'     => 10,
    ]));
    expect($collection->resource)->toHaveCount(1)
        ->and($collection->resource->first()->uuid)->toBe('matching-transaction');

    expect($controller->freeze($wallet->uuid, new Request())->resource->status)->toBe('frozen')
        ->and($controller->unfreeze($wallet->uuid, new Request())->resource->status)->toBe('active');

    $recalculated = walletControllerJson($controller->recalculate($wallet->uuid, new Request()));
    expect($recalculated)->toMatchArray([
        'old_balance' => 2500,
        'new_balance' => 4321,
        'corrected'   => true,
    ]);
    $unchanged = walletControllerJson($controller->recalculate($wallet->uuid, new Request()));
    expect($unchanged['corrected'])->toBeFalse();
});

test('internal wallet resolution rejects cross-company identifiers', function () {
    $service    = new WalletControllerService();
    $controller = new WalletController($service);
    walletControllerWallet(['uuid' => 'foreign-wallet', 'company_uuid' => 'another-company']);

    expect(fn () => $controller->freeze('foreign-wallet', new Request()))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

test('wallet API provisions consumer wallets and returns exact balance contracts', function () {
    $service    = new WalletControllerService();
    $controller = new WalletApiController($service);
    $consumer   = new Company(['uuid' => 'consumer-company']);
    $request    = walletControllerRequest(['_consumer' => $consumer, 'currency' => 'MNT']);

    $resource = $controller->getWallet($request);
    $balance  = walletControllerJson($controller->getBalance($request));
    expect($resource->resource)->toBeInstanceOf(Wallet::class)
        ->and($balance)->toMatchArray([
            'balance'           => 2500,
            'formatted_balance' => '25.00',
            'currency'          => 'USD',
            'status'            => 'active',
        ])
        ->and($service->calls[0])->toBe(['getOrCreateWallet', 'consumer-company', 'MNT']);
});

test('wallet API resolves authenticated users and rejects unauthenticated requests', function () {
    $service    = new WalletControllerService();
    $controller = new WalletApiController($service);
    $user       = new Company(['uuid' => 'authenticated-company']);
    $request    = walletControllerRequest();
    $request->setUserResolver(fn () => $user);

    expect($controller->getWallet($request)->resource)->toBeInstanceOf(Wallet::class);

    $unauthenticated = walletControllerRequest();
    $unauthenticated->setUserResolver(fn () => null);
    // AuthenticationException, not HttpException: abort(401) rendered Laravel's HTML
    // error page, so a client parsing JSON got 1.8 KB of markup instead of an error body.
    session(['user' => null]);
    expect(fn () => $controller->getWallet($unauthenticated))
        ->toThrow(Illuminate\Auth\AuthenticationException::class);
});

test('wallet API resolves the session user when no user resolver is bound', function () {
    // The production path for every public API request. `fleetbase.api` authenticates
    // with Auth::setSession(), which writes session('user') but leaves $login false — so
    // no user resolver is bound and $request->user() is null. Reading it alone made all
    // four wallet routes answer 401 to every credential, including a driver's own Sanctum
    // token, which authenticates fine against every other public endpoint.
    $service    = new WalletControllerService();
    $controller = new WalletApiController($service);

    Fleetbase\Models\User::query()->getConnection()->table('users')->insert([
        'uuid'         => 'session-user-uuid',
        'company_uuid' => 'company-wallet-controller',
    ]);
    session(['user' => 'session-user-uuid']);

    $request = walletControllerRequest();
    $request->setUserResolver(fn () => null);

    expect($controller->getWallet($request)->resource)->toBeInstanceOf(Wallet::class)
        ->and($service->calls[0])->toBe(['getOrCreateWallet', 'session-user-uuid', 'USD']);

    session(['user' => null]);
});

test('wallet API history is scoped to the provisioned wallet and honors filters', function () {
    $service    = new WalletControllerService();
    $controller = new WalletApiController($service);
    $wallet     = $service->wallet = walletControllerWallet();
    walletControllerTransaction($wallet, [
        'uuid'       => 'api-history-match',
        'type'       => 'deposit',
        'direction'  => 'credit',
        'status'     => 'completed',
        'created_at' => '2026-07-20 12:00:00',
    ]);
    walletControllerTransaction($wallet, [
        'uuid'       => 'api-history-excluded',
        'type'       => 'payout',
        'direction'  => 'debit',
        'status'     => 'pending',
        'created_at' => '2026-07-10 12:00:00',
    ]);

    $request = walletControllerRequest([
        '_consumer' => new Company(['uuid' => 'consumer-company']),
        'type'      => 'deposit',
        'direction' => 'credit',
        'status'    => 'completed',
        'date_from' => '2026-07-15',
        'date_to'   => '2026-07-25',
        'limit'     => 5,
    ]);
    $collection = $controller->getTransactions($request);
    expect($collection->resource)->toHaveCount(1)
        ->and($collection->resource->first()->uuid)->toBe('api-history-match');
});

test('wallet API top ups map gateway responses, optional transactions, and safe failures', function () {
    $service    = new WalletControllerService();
    $controller = new WalletApiController($service);
    $consumer   = new Company(['uuid' => 'consumer-company']);
    $request    = walletControllerRequest([
        '_consumer'            => $consumer,
        'gateway'              => 'gateway_public',
        'amount'               => 1500,
        'payment_method_token' => 'pm_test',
        'customer_id'          => 'customer-1',
    ]);

    $pending = walletControllerJson($controller->topUp($request));
    expect($pending['gateway_response'])->toBe([
        'status'               => 'pending',
        'event_type'           => GatewayResponse::EVENT_PAYMENT_PENDING,
        'gateway_reference_id' => 'gateway-pending',
        'data'                 => ['payment_url' => 'https://pay.test'],
    ])->and($pending)->not->toHaveKey('transaction');

    $service->transaction = walletControllerTransaction(walletControllerWallet());
    expect(walletControllerJson($controller->topUp($request)))->toHaveKey('transaction');

    $service->topUpException = new RuntimeException('Provider unavailable');
    $failure                 = $controller->topUp($request);
    expect($failure->getStatusCode())->toBe(422)
        ->and(walletControllerJson($failure))->toBe(['error' => 'Provider unavailable']);
});

test('transaction resources serialize loaded polymorphic identities and item contracts', function () {
    $wallet      = walletControllerWallet();
    $transaction = walletControllerTransaction($wallet);
    $transaction->setRelation('subject', null);
    $transaction->setRelation('payer', (object) [
        'uuid'      => 'payer-uuid',
        'public_id' => 'payer_public',
        'name'      => 'Primary payer',
    ]);
    $transaction->setRelation('payee', (object) [
        'uuid'         => 'payee-uuid',
        'display_name' => 'Display payee',
    ]);
    $transaction->setRelation('initiator', (object) [
        'uuid'      => 'initiator-uuid',
        'full_name' => 'Full initiator',
    ]);
    $transaction->setRelation('context', (object) [
        'public_id' => 'context_public',
        'email'     => 'context@example.test',
    ]);
    $transaction->setRelation('items', new Collection([
        (object) [
            'uuid'     => 'item-uuid',
            'amount'   => 500,
            'currency' => 'USD',
            'details'  => 'Service',
            'code'     => 'SERVICE',
            'meta'     => ['taxable' => true],
        ],
    ]));

    $payload = (new TransactionResource($transaction))->toArray(new Request());
    expect($payload['subject'])->toBeNull()
        ->and($payload['payer'])->toMatchArray([
            'uuid'      => 'payer-uuid',
            'public_id' => 'payer_public',
            'name'      => 'Primary payer',
        ])
        ->and($payload['payer_name'])->toBe('Primary payer')
        ->and($payload['payee_name'])->toBe('Display payee')
        ->and($payload['initiator_name'])->toBe('Full initiator')
        ->and($payload['context'])->toMatchArray([
            'public_id' => 'context_public',
            'name'      => 'context@example.test',
        ])
        ->and($payload['items']->first())->toBe([
            'uuid'     => 'item-uuid',
            'amount'   => 500,
            'currency' => 'USD',
            'details'  => 'Service',
            'code'     => 'SERVICE',
            'meta'     => ['taxable' => true],
        ]);

    $resource    = new TransactionResource($transaction);
    $displayName = new ReflectionMethod(TransactionResource::class, 'resolveDisplayName');
    $polymorphic = new ReflectionMethod(TransactionResource::class, 'resolvePolymorphicResource');
    $displayName->setAccessible(true);
    $polymorphic->setAccessible(true);
    expect($displayName->invoke($resource, null))->toBeNull()
        ->and($polymorphic->invoke($resource, null))->toBeNull();
});
