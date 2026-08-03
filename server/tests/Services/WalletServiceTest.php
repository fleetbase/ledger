<?php

use Fleetbase\Ledger\DTO\GatewayResponse;
use Fleetbase\Ledger\DTO\PurchaseRequest;
use Fleetbase\Ledger\Models\Account;
use Fleetbase\Ledger\Models\GatewayTransaction;
use Fleetbase\Ledger\Models\Journal;
use Fleetbase\Ledger\Models\Transaction;
use Fleetbase\Ledger\Models\Wallet;
use Fleetbase\Ledger\Services\LedgerService;
use Fleetbase\Ledger\Services\PaymentService;
use Fleetbase\Ledger\Services\WalletService;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Fleetbase\TestSupport\LoggerManager;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

class WalletSubjectModel extends Model
{
    protected $guarded = [];
}

class WalletLedgerSpy extends LedgerService
{
    public array $calls = [];

    public function createJournalEntry(
        Account $debitAccount,
        Account $creditAccount,
        int $amount,
        string $description = '',
        array $options = [],
    ): Journal {
        $this->calls[] = compact('debitAccount', 'creditAccount', 'amount', 'description', 'options');

        return new Journal();
    }
}

class WalletPaymentStub extends PaymentService
{
    public array $responses = [];
    public array $calls     = [];

    public function __construct()
    {
    }

    public function charge(string $gatewayIdentifier, PurchaseRequest $request): GatewayResponse
    {
        $this->calls[] = compact('gatewayIdentifier', 'request');

        return array_shift($this->responses);
    }
}

class WalletServiceProbe extends WalletService
{
    public function usePaymentService(?PaymentService $paymentService): void
    {
        $this->paymentService = $paymentService;
    }

    public function paymentService(): PaymentService
    {
        return $this->resolvePaymentService();
    }

    public function walletAccount(Wallet $wallet): Account
    {
        return $this->getWalletAccount($wallet);
    }

    public function cashAccount(string $companyUuid): Account
    {
        return $this->getDefaultCashAccount($companyUuid);
    }
}

function bootWalletServiceDatabase(): void
{
    $capsule    = new Capsule(Container::getInstance());
    $dispatcher = new Dispatcher(Container::getInstance());
    $capsule->setEventDispatcher($dispatcher);
    Model::setEventDispatcher($dispatcher);
    Model::clearBootedModels();
    $capsule->addConnection([
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ], 'testing');
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('testing');
    Container::getInstance()->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstance('db');

    $schema = $capsule->getConnection('testing')->getSchemaBuilder();
    $schema->create('ledger_wallets', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('_key')->nullable();
        $table->string('company_uuid')->nullable();
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
        $table->unique(
            ['company_uuid', 'subject_uuid', 'subject_type', 'name'],
            'ledger_wallets_company_subject_name_unique'
        );
    });
    $schema->create('ledger_accounts', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('_key')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('created_by_uuid')->nullable();
        $table->string('updated_by_uuid')->nullable();
        $table->string('name');
        $table->string('code');
        $table->string('type');
        $table->text('description')->nullable();
        $table->boolean('is_system_account')->default(false);
        $table->bigInteger('balance')->default(0);
        $table->string('currency')->nullable();
        $table->string('status')->default('active');
        $table->text('meta')->nullable();
        $table->softDeletes();
        $table->timestamps();
        $table->unique(['company_uuid', 'code']);
    });
    $schema->create('transactions', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('company_uuid')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('owner_type')->nullable();
        $table->string('gateway_transaction_id')->nullable();
        $table->string('type');
        $table->string('direction');
        $table->string('status');
        $table->string('settlement_status')->nullable();
        $table->bigInteger('amount');
        $table->bigInteger('balance_after')->nullable();
        $table->string('currency')->nullable();
        $table->timestamp('settled_at')->nullable();
        $table->bigInteger('settled_amount')->nullable();
        $table->string('settled_currency')->nullable();
        $table->text('description')->nullable();
        $table->string('reference')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->text('meta')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('ledger_gateway_transactions', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('gateway_reference_id')->nullable();
        $table->string('type')->nullable();
        $table->string('event_type')->nullable();
        $table->string('status')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
}

function walletFixture(
    string $name,
    int $balance = 0,
    string $status = Wallet::STATUS_ACTIVE,
    string $company = 'company-wallet',
    string $currency = 'USD',
): Wallet {
    static $sequence = 0;
    $sequence++;

    return Wallet::create([
        'uuid'         => sprintf('50000000-0000-4000-8000-%012d', $sequence),
        'public_id'    => 'wallet_' . strtolower(str_replace(' ', '_', $name)) . '_' . $sequence,
        'company_uuid' => $company,
        'subject_uuid' => 'subject-wallet-' . $sequence,
        'subject_type' => Company::class,
        'name'         => $name,
        'balance'      => $balance,
        'currency'     => $currency,
        'status'       => $status,
    ]);
}

beforeEach(function () {
    bootWalletServiceDatabase();
    session(['company' => 'company-wallet']);
    LoggerManager::$records = [];
});

test('wallet provisioning supports individual and batch subjects idempotently', function () {
    $ledger  = new WalletLedgerSpy();
    $service = new WalletService($ledger);
    $first   = new Company(['uuid' => 'subject-one', 'company_uuid' => 'company-wallet']);
    $second  = new Company(['uuid' => 'subject-two', 'company_uuid' => null]);

    $wallet = $service->getOrCreateWallet($first, 'MNT');
    $again  = $service->getOrCreateWallet($first, 'MNT');
    $batch  = $service->provisionBatch([$first, $second], 'EUR');

    expect($wallet->is($again))->toBeTrue()
        ->and($wallet->currency)->toBe('MNT')
        ->and($batch)->toHaveCount(2)
        ->and($batch[0]->is($wallet))->toBeTrue()
        ->and($batch[1]->company_uuid)->toBe('company-wallet')
        ->and(Wallet::query()->count())->toBe(2);
});

test('deposits persist audit details update balances and post the liability journal', function () {
    $ledger  = new WalletLedgerSpy();
    $service = new WalletService($ledger);
    $wallet  = walletFixture('Deposit Wallet', 100);
    $source  = Account::create([
        'uuid' => 'source-account', 'public_id' => 'account_source', 'company_uuid' => 'company-wallet',
        'name' => 'Source', 'code' => 'SOURCE', 'type' => Account::TYPE_ASSET, 'status' => 'active',
    ]);

    $transaction = $service->deposit($wallet, 500, options: [
        'source_account'            => $source,
        'reference'                 => 'deposit-ref',
        'gateway_transaction_uuid'  => 'gateway-audit',
        'subject_uuid'              => 'order-deposit',
        'subject_type'              => 'order',
        'meta'                      => ['reason' => 'manual'],
    ]);

    expect($wallet->fresh()->balance)->toBe(600)
        ->and($transaction->type)->toBe('deposit')
        ->and($transaction->direction)->toBe('credit')
        ->and($transaction->status)->toBe(Transaction::STATUS_SUCCESS)
        ->and($transaction->settlement_status)->toBe(Transaction::SETTLEMENT_STATUS_PAID)
        ->and($transaction->balance_after)->toBe(600)
        ->and($transaction->description)->toContain('Deposit to wallet')
        ->and($transaction->gateway_transaction_id)->toBe('gateway-audit')
        ->and($transaction->meta['reason'])->toBe('manual')
        ->and($ledger->calls)->toHaveCount(1)
        ->and($ledger->calls[0]['debitAccount']->is($source))->toBeTrue()
        ->and($ledger->calls[0]['creditAccount']->code)->toBe('WALLET-' . $wallet->uuid)
        ->and($ledger->calls[0]['options']['transaction_uuid'])->toBe($transaction->uuid);
});

test('deposit validation rejects closed wallets and non-positive amounts', function () {
    $service = new WalletService(new WalletLedgerSpy());
    $closed  = walletFixture('Closed Deposit', status: Wallet::STATUS_CLOSED);
    $active  = walletFixture('Zero Deposit');

    expect(fn () => $service->deposit($closed, 100))->toThrow(Exception::class, 'cannot accept credits')
        ->and(fn () => $service->deposit($active, 0))->toThrow(InvalidArgumentException::class, 'greater than zero');
});

test('withdrawals debit the wallet and use custom or default destination accounts', function () {
    $ledger      = new WalletLedgerSpy();
    $service     = new WalletService($ledger);
    $wallet      = walletFixture('Withdrawal Wallet', 1000);
    $destination = Account::create([
        'uuid' => 'destination-account', 'public_id' => 'account_destination', 'company_uuid' => 'company-wallet',
        'name' => 'Destination', 'code' => 'DESTINATION', 'type' => Account::TYPE_ASSET, 'status' => 'active',
    ]);

    $first = $service->withdraw($wallet, 300, options: [
        'destination_account'       => $destination,
        'reference'                 => 'withdraw-ref',
        'gateway_transaction_uuid'  => 'withdraw-gateway',
        'meta'                      => ['bank' => 'test'],
    ]);
    $second = $service->withdraw($wallet->fresh(), 100, 'Payout', 'payout');

    expect($wallet->fresh()->balance)->toBe(600)
        ->and($first->direction)->toBe('debit')
        ->and($first->balance_after)->toBe(700)
        ->and($second->type)->toBe('payout')
        ->and($second->description)->toBe('Payout')
        ->and($ledger->calls)->toHaveCount(2)
        ->and($ledger->calls[0]['debitAccount']->code)->toStartWith('WALLET-')
        ->and($ledger->calls[0]['creditAccount']->is($destination))->toBeTrue()
        ->and($ledger->calls[1]['creditAccount']->code)->toBe('CASH-DEFAULT');
});

test('withdrawal validation distinguishes state balance and amount failures', function () {
    $service = new WalletService(new WalletLedgerSpy());
    $frozen  = walletFixture('Frozen Withdrawal', 1000, Wallet::STATUS_FROZEN);
    $poor    = walletFixture('Poor Withdrawal', 50);
    $active  = walletFixture('Zero Withdrawal', 100);

    expect(fn () => $service->withdraw($frozen, 100))->toThrow(Exception::class, 'cannot be debited')
        ->and(fn () => $service->withdraw($poor, 100))->toThrow(Exception::class, 'Insufficient balance')
        ->and(fn () => $service->withdraw($active, 0))->toThrow(InvalidArgumentException::class, 'greater than zero');
});

test('transfers atomically create both audit sides and a linked liability journal', function () {
    $ledger  = new WalletLedgerSpy();
    $service = new WalletService($ledger);
    $from    = walletFixture('Transfer Source', 1000);
    $to      = walletFixture('Transfer Destination', 50, Wallet::STATUS_FROZEN);

    $result = $service->transfer($from, $to, 400, options: [
        'reference' => 'transfer-ref',
        'meta'      => ['batch' => 'batch-1'],
    ]);

    expect($from->fresh()->balance)->toBe(600)
        ->and($to->fresh()->balance)->toBe(450)
        ->and($result['from']->type)->toBe('transfer_out')
        ->and($result['to']->type)->toBe('transfer_in')
        ->and($result['from']->meta['to_wallet_uuid'])->toBe($to->uuid)
        ->and($result['to']->meta['from_wallet_uuid'])->toBe($from->uuid)
        ->and($ledger->calls)->toHaveCount(1)
        ->and($ledger->calls[0]['options']['meta']['from_transaction_uuid'])->toBe($result['from']->uuid)
        ->and($ledger->calls[0]['options']['meta']['to_transaction_uuid'])->toBe($result['to']->uuid);
});

test('transfer validation rejects invalid source destination balance and amount', function () {
    $service = new WalletService(new WalletLedgerSpy());
    $active  = walletFixture('Transfer Active', 100);
    $frozen  = walletFixture('Transfer Frozen', 100, Wallet::STATUS_FROZEN);
    $closed  = walletFixture('Transfer Closed', 0, Wallet::STATUS_CLOSED);

    expect(fn () => $service->transfer($frozen, $active, 10))->toThrow(Exception::class, 'Source wallet')
        ->and(fn () => $service->transfer($active, $closed, 10))->toThrow(Exception::class, 'Destination wallet')
        ->and(fn () => $service->transfer($active, $frozen, 200))->toThrow(Exception::class, 'Insufficient balance')
        ->and(fn () => $service->transfer($active, $frozen, 0))->toThrow(InvalidArgumentException::class, 'greater than zero');
});

test('synchronous gateway top ups credit immediately while pending responses wait', function () {
    $ledger  = new WalletLedgerSpy();
    $payment = new WalletPaymentStub();
    $service = new WalletServiceProbe($ledger);
    $service->usePaymentService($payment);
    $wallet = walletFixture('Top Up Wallet');

    GatewayTransaction::create([
        'uuid'                 => 'gateway-audit-topup', 'public_id' => 'gtxn_topup',
        'gateway_reference_id' => 'gateway-success', 'type' => 'purchase',
        'event_type'           => GatewayResponse::EVENT_PAYMENT_SUCCEEDED, 'status' => GatewayResponse::STATUS_SUCCEEDED,
    ]);
    $payment->responses[] = GatewayResponse::success(
        'gateway-success',
        GatewayResponse::EVENT_PAYMENT_SUCCEEDED,
        amount: 700,
        currency: 'USD',
    );
    $payment->responses[] = GatewayResponse::pending('gateway-pending');

    $success = $service->topUp($wallet, 700, 'gateway-one', [
        'payment_method_token' => 'token-1',
        'customer_id'          => 'customer-1',
        'customer_email'       => 'test@example.test',
        'metadata'             => ['campaign' => 'summer'],
    ]);
    $pending = $service->topUp($wallet->fresh(), 300, 'gateway-two', description: 'Async top-up');

    expect($success['transaction'])->toBeInstanceOf(Transaction::class)
        ->and($success['wallet']->balance)->toBe(700)
        ->and($success['transaction']->gateway_transaction_id)->toBe('gateway-audit-topup')
        ->and($pending['transaction'])->toBeNull()
        ->and($pending['wallet']->balance)->toBe(700)
        ->and($payment->calls[0]['request']->metadata['wallet_uuid'])->toBe($wallet->uuid)
        ->and($payment->calls[0]['request']->metadata['campaign'])->toBe('summer')
        ->and($payment->calls[1]['request']->description)->toBe('Async top-up');
});

test('driver earnings and payouts reuse wallet lifecycle contracts', function () {
    $ledger  = new WalletLedgerSpy();
    $service = new WalletService($ledger);
    $driver  = new Company(['uuid' => 'driver-one', 'company_uuid' => 'company-wallet']);

    $earning = $service->creditEarnings($driver, 900, 'MNT', options: ['reference' => 'order-one']);
    $payout  = $service->processPayout($driver, 400, options: ['currency' => 'MNT', 'reference' => 'payout-one']);

    expect($earning->type)->toBe('earning')
        ->and($earning->subject_uuid)->toBe('driver-one')
        ->and($earning->currency)->toBe('MNT')
        ->and($payout->type)->toBe('payout')
        ->and($payout->subject_type)->toBe(Company::class)
        ->and(Wallet::query()->first()->balance)->toBe(500);
});

test('balance reconciliation corrects drift and leaves accurate balances untouched', function () {
    $service = new WalletService(new WalletLedgerSpy());
    $wallet  = walletFixture('Reconcile Wallet', 999);
    Capsule::table('transactions')->insert([
        ['uuid' => 'reconcile-credit', 'public_id' => 'transaction_reconcile_credit', 'company_uuid' => 'company-wallet', 'owner_uuid' => $wallet->uuid, 'type' => 'deposit', 'direction' => 'credit', 'status' => 'completed', 'amount' => 500, 'created_at' => now(), 'updated_at' => now()],
        ['uuid' => 'reconcile-debit', 'public_id' => 'transaction_reconcile_debit', 'company_uuid' => 'company-wallet', 'owner_uuid' => $wallet->uuid, 'type' => 'withdrawal', 'direction' => 'debit', 'status' => 'completed', 'amount' => 125, 'created_at' => now(), 'updated_at' => now()],
        ['uuid' => 'reconcile-pending', 'public_id' => 'transaction_reconcile_pending', 'company_uuid' => 'company-wallet', 'owner_uuid' => $wallet->uuid, 'type' => 'deposit', 'direction' => 'credit', 'status' => 'pending', 'amount' => 9999, 'created_at' => now(), 'updated_at' => now()],
    ]);

    expect($service->recalculateBalance($wallet))->toBe(375)
        ->and($wallet->fresh()->balance)->toBe(375)
        ->and(LoggerManager::$records[array_key_last(LoggerManager::$records)]['level'])->toBe('warning');

    LoggerManager::$records = [];
    expect($service->recalculateBalance($wallet->fresh()))->toBe(375)
        ->and(LoggerManager::$records)->toBeEmpty();
});

test('company and user provisioning is idempotent and honors currency fallbacks', function () {
    $service = new WalletService(new WalletLedgerSpy());
    $company = new Company(['uuid' => 'company-provision', 'currency' => 'MNT']);
    $company->setConnection('testing');

    $wallets = $service->provisionCompanyWallets($company);
    $again   = $service->provisionCompanyWallets($company);

    $orphan               = new User();
    $orphan->company_uuid = null;
    $user                 = new User();
    $user->uuid           = 'user-provision';
    $user->company_uuid   = 'company-provision';
    $user->setRelation('company', $company);
    $userWallet = $service->provisionUserWallet($user);

    expect($wallets)->toHaveCount(4)
        ->and($again->pluck('uuid')->all())->toBe($wallets->pluck('uuid')->all())
        ->and($wallets->pluck('name')->all())->toBe([
            'Operating Wallet', 'Revenue Wallet', 'Payout Reserve', 'Refund Reserve',
        ])
        ->and($wallets->every(fn (Wallet $wallet) => $wallet->currency === 'MNT'))->toBeTrue()
        ->and($service->provisionUserWallet($orphan))->toBeNull()
        ->and($userWallet->name)->toBe('Personal Wallet')
        ->and($userWallet->currency)->toBe('MNT')
        ->and($service->provisionUserWallet($user)->is($userWallet))->toBeTrue();
});

test('protected account and payment resolution helpers cache deterministic dependencies', function () {
    $ledger  = new WalletLedgerSpy();
    $service = new WalletServiceProbe($ledger);
    $wallet  = walletFixture('Helper Wallet');
    $payment = new WalletPaymentStub();
    Container::getInstance()->instance(PaymentService::class, $payment);

    $walletAccount = $service->walletAccount($wallet);
    $cashAccount   = $service->cashAccount('company-wallet');

    expect($service->walletAccount($wallet)->is($walletAccount))->toBeTrue()
        ->and($service->cashAccount('company-wallet')->is($cashAccount))->toBeTrue()
        ->and($service->paymentService())->toBe($payment)
        ->and($service->paymentService())->toBe($payment);
});
