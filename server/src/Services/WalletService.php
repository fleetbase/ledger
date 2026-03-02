<?php

namespace Fleetbase\Ledger\Services;

use Fleetbase\Ledger\DTO\PurchaseRequest;
use Fleetbase\Ledger\Models\Account;
use Fleetbase\Ledger\Models\Transaction;
use Fleetbase\Ledger\Models\Wallet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WalletService.
 *
 * Manages all wallet lifecycle operations for the Ledger extension.
 *
 * This service is the single entry point for all wallet operations.
 * Every balance change MUST go through this service to ensure:
 *   1. Correct double-entry journal entries are created
 *   2. Transaction audit records are persisted
 *   3. Wallet balance is updated atomically within a DB transaction
 *
 * Monetary values are always in the smallest currency unit (cents).
 */
class WalletService
{
    /**
     * The ledger service instance.
     */
    protected LedgerService $ledgerService;

    /**
     * The payment service instance (lazy-resolved to avoid circular deps).
     */
    protected ?PaymentService $paymentService = null;

    public function __construct(LedgerService $ledgerService)
    {
        $this->ledgerService = $ledgerService;
    }

    // =========================================================================
    // Provisioning
    // =========================================================================

    /**
     * Get or create a wallet for any subject (driver, customer, company, etc.).
     *
     * This is the primary auto-provisioning method. Call this whenever you need
     * a wallet for a subject and don't know if one exists yet.
     *
     * @param Model  $subject  Any Eloquent model with a uuid and company_uuid
     * @param string $currency ISO 4217 currency code (default: USD)
     */
    public function getOrCreateWallet(Model $subject, string $currency = 'USD'): Wallet
    {
        return Wallet::forSubject(
            subjectUuid: $subject->uuid,
            subjectType: get_class($subject),
            companyUuid: $subject->company_uuid ?? session('company'),
            currency: $currency,
        );
    }

    /**
     * Provision wallets for a batch of subjects.
     * Useful for seeding wallets for all existing drivers or customers.
     *
     * @param iterable $subjects Collection of Eloquent models
     *
     * @return array<Wallet>
     */
    public function provisionBatch(iterable $subjects, string $currency = 'USD'): array
    {
        $wallets = [];
        foreach ($subjects as $subject) {
            $wallets[] = $this->getOrCreateWallet($subject, $currency);
        }

        return $wallets;
    }

    // =========================================================================
    // Deposit
    // =========================================================================

    /**
     * Deposit funds into a wallet from an internal source (e.g., manual credit, earning).
     *
     * Double-entry accounting:
     *   DEBIT  Cash / Source Account  (asset increases — money received)
     *   CREDIT Wallet Liability       (liability increases — we owe more to wallet holder)
     *
     * @param int    $amount  Amount in smallest currency unit (cents)
     * @param string $type    Transaction type (default: 'deposit')
     * @param array  $options Optional: source_account, reference, subject, meta, gateway_transaction_uuid
     *
     * @throws \Exception if wallet is not active or cannot credit
     */
    public function deposit(
        Wallet $wallet,
        int $amount,
        string $description = '',
        string $type = 'deposit',
        array $options = [],
    ): Transaction {
        if (!$wallet->canCredit()) {
            throw new \Exception("Wallet [{$wallet->public_id}] cannot accept credits (status: {$wallet->status}).");
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Deposit amount must be greater than zero.');
        }

        return DB::transaction(function () use ($wallet, $amount, $description, $type, $options) {
            // Double-entry: DEBIT Cash, CREDIT Wallet Liability
            $cashAccount   = $options['source_account'] ?? $this->getDefaultCashAccount($wallet->company_uuid);
            $walletAccount = $this->getWalletAccount($wallet);

            $this->ledgerService->createJournalEntry(
                $cashAccount,
                $walletAccount,
                $amount,
                $description ?: "Deposit to wallet {$wallet->public_id}",
                array_merge($options, [
                    'company_uuid' => $wallet->company_uuid,
                    'currency'     => $wallet->currency,
                    'type'         => 'wallet_deposit',
                ])
            );

            // Update wallet balance
            $newBalance = $wallet->credit($amount);

            // Create Transaction audit record (owner = wallet)
            $transaction = Transaction::create([
                'company_uuid'           => $wallet->company_uuid,
                'owner_uuid'             => $wallet->uuid,
                'owner_type'             => Wallet::class,
                'gateway_transaction_id' => $options['gateway_transaction_uuid'] ?? null,
                'type'                   => $type,
                'direction'              => 'credit',
                'status'                 => 'completed',
                'amount'                 => $amount,
                'balance_after'          => $newBalance,
                'currency'               => $wallet->currency,
                'description'            => $description ?: "Deposit to wallet {$wallet->public_id}",
                'reference'              => $options['reference'] ?? null,
                'subject_uuid'           => $options['subject_uuid'] ?? null,
                'subject_type'           => $options['subject_type'] ?? null,
                'meta'                   => $options['meta'] ?? null,
            ]);

            Log::channel('ledger')->info('Wallet deposit completed.', [
                'wallet_uuid'    => $wallet->uuid,
                'amount'         => $amount,
                'new_balance'    => $newBalance,
                'transaction_id' => $transaction->public_id,
            ]);

            return $transaction;
        });
    }

    // =========================================================================
    // Withdrawal
    // =========================================================================

    /**
     * Withdraw funds from a wallet to an external destination.
     *
     * Double-entry accounting:
     *   DEBIT  Wallet Liability       (liability decreases — we owe less to wallet holder)
     *   CREDIT Cash / Dest Account    (asset decreases — money paid out)
     *
     * @param int    $amount  Amount in smallest currency unit (cents)
     * @param string $type    Transaction type (default: 'withdrawal')
     * @param array  $options Optional: destination_account, reference, subject, meta
     *
     * @throws \Exception if wallet cannot debit or has insufficient balance
     */
    public function withdraw(
        Wallet $wallet,
        int $amount,
        string $description = '',
        string $type = 'withdrawal',
        array $options = [],
    ): Transaction {
        if (!$wallet->canDebit()) {
            throw new \Exception("Wallet [{$wallet->public_id}] cannot be debited (status: {$wallet->status}).");
        }

        if (!$wallet->hasSufficientBalance($amount)) {
            throw new \Exception("Insufficient balance in wallet [{$wallet->public_id}]. Available: {$wallet->balance}, Required: {$amount}.");
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Withdrawal amount must be greater than zero.');
        }

        return DB::transaction(function () use ($wallet, $amount, $description, $type, $options) {
            // Double-entry: DEBIT Wallet Liability, CREDIT Cash
            $walletAccount = $this->getWalletAccount($wallet);
            $cashAccount   = $options['destination_account'] ?? $this->getDefaultCashAccount($wallet->company_uuid);

            $this->ledgerService->createJournalEntry(
                $walletAccount,
                $cashAccount,
                $amount,
                $description ?: "Withdrawal from wallet {$wallet->public_id}",
                array_merge($options, [
                    'company_uuid' => $wallet->company_uuid,
                    'currency'     => $wallet->currency,
                    'type'         => 'wallet_withdrawal',
                ])
            );

            // Update wallet balance
            $newBalance = $wallet->debit($amount);

            // Create Transaction audit record (owner = wallet)
            $transaction = Transaction::create([
                'company_uuid'           => $wallet->company_uuid,
                'owner_uuid'             => $wallet->uuid,
                'owner_type'             => Wallet::class,
                'gateway_transaction_id' => $options['gateway_transaction_uuid'] ?? null,
                'type'                   => $type,
                'direction'              => 'debit',
                'status'                 => 'completed',
                'amount'                 => $amount,
                'balance_after'          => $newBalance,
                'currency'               => $wallet->currency,
                'description'            => $description ?: "Withdrawal from wallet {$wallet->public_id}",
                'reference'              => $options['reference'] ?? null,
                'subject_uuid'           => $options['subject_uuid'] ?? null,
                'subject_type'           => $options['subject_type'] ?? null,
                'meta'                   => $options['meta'] ?? null,
            ]);

            Log::channel('ledger')->info('Wallet withdrawal completed.', [
                'wallet_uuid'    => $wallet->uuid,
                'amount'         => $amount,
                'new_balance'    => $newBalance,
                'transaction_id' => $transaction->public_id,
            ]);

            return $transaction;
        });
    }

    // =========================================================================
    // Transfer
    // =========================================================================

    /**
     * Transfer funds between two wallets within the system.
     *
     * Double-entry accounting:
     *   DEBIT  From Wallet Liability  (source liability decreases — we owe less to source holder)
     *   CREDIT To Wallet Liability    (destination liability increases — we owe more to dest holder)
     *
     * @param int $amount Amount in smallest currency unit (cents)
     *
     * @return array{from: Transaction, to: Transaction}
     *
     * @throws \Exception if either wallet cannot operate or source has insufficient balance
     */
    public function transfer(
        Wallet $fromWallet,
        Wallet $toWallet,
        int $amount,
        string $description = '',
        array $options = [],
    ): array {
        if (!$fromWallet->canDebit()) {
            throw new \Exception("Source wallet [{$fromWallet->public_id}] cannot be debited.");
        }

        if (!$toWallet->canCredit()) {
            throw new \Exception("Destination wallet [{$toWallet->public_id}] cannot accept credits.");
        }

        if (!$fromWallet->hasSufficientBalance($amount)) {
            throw new \Exception("Insufficient balance in source wallet [{$fromWallet->public_id}]. Available: {$fromWallet->balance}, Required: {$amount}.");
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Transfer amount must be greater than zero.');
        }

        return DB::transaction(function () use ($fromWallet, $toWallet, $amount, $description, $options) {
            $desc = $description ?: "Transfer from {$fromWallet->public_id} to {$toWallet->public_id}";

            // Double-entry: DEBIT From Wallet Liability, CREDIT To Wallet Liability
            $fromAccount = $this->getWalletAccount($fromWallet);
            $toAccount   = $this->getWalletAccount($toWallet);

            $this->ledgerService->createJournalEntry(
                $fromAccount,
                $toAccount,
                $amount,
                $desc,
                array_merge($options, [
                    'company_uuid' => $fromWallet->company_uuid,
                    'currency'     => $fromWallet->currency,
                    'type'         => 'wallet_transfer',
                ])
            );

            // Update balances
            $fromNewBalance = $fromWallet->debit($amount);
            $toNewBalance   = $toWallet->credit($amount);

            $reference = $options['reference'] ?? null;

            // Create Transaction records for both sides (owner = respective wallet)
            $fromTransaction = Transaction::create([
                'company_uuid'  => $fromWallet->company_uuid,
                'owner_uuid'    => $fromWallet->uuid,
                'owner_type'    => Wallet::class,
                'type'          => 'transfer_out',
                'direction'     => 'debit',
                'status'        => 'completed',
                'amount'        => $amount,
                'balance_after' => $fromNewBalance,
                'currency'      => $fromWallet->currency,
                'description'   => $desc,
                'reference'     => $reference,
                'meta'          => array_merge($options['meta'] ?? [], [
                    'to_wallet_uuid'      => $toWallet->uuid,
                    'to_wallet_public_id' => $toWallet->public_id,
                ]),
            ]);

            $toTransaction = Transaction::create([
                'company_uuid'  => $toWallet->company_uuid,
                'owner_uuid'    => $toWallet->uuid,
                'owner_type'    => Wallet::class,
                'type'          => 'transfer_in',
                'direction'     => 'credit',
                'status'        => 'completed',
                'amount'        => $amount,
                'balance_after' => $toNewBalance,
                'currency'      => $toWallet->currency,
                'description'   => $desc,
                'reference'     => $reference,
                'meta'          => array_merge($options['meta'] ?? [], [
                    'from_wallet_uuid'      => $fromWallet->uuid,
                    'from_wallet_public_id' => $fromWallet->public_id,
                ]),
            ]);

            Log::channel('ledger')->info('Wallet transfer completed.', [
                'from_wallet' => $fromWallet->uuid,
                'to_wallet'   => $toWallet->uuid,
                'amount'      => $amount,
            ]);

            return [
                'from' => $fromTransaction,
                'to'   => $toTransaction,
            ];
        });
    }

    // =========================================================================
    // Top-Up via Payment Gateway
    // =========================================================================

    /**
     * Top up a wallet by charging a payment gateway.
     *
     * This method:
     *   1. Initiates a charge via the PaymentService
     *   2. On success, deposits the amount into the wallet
     *   3. Links the GatewayTransaction to the Transaction record
     *
     * For asynchronous gateways (QPay), the wallet is credited when the
     * PaymentSucceeded event fires via the HandleSuccessfulPayment listener.
     * For synchronous gateways (Stripe confirmed, Cash), the wallet is
     * credited immediately.
     *
     * @param int    $amount      Amount in smallest currency unit (cents)
     * @param string $gatewayUuid UUID or public_id of the gateway to charge
     * @param array  $paymentData Payment data (payment_method_token, customer_id, etc.)
     *
     * @return array{wallet: Wallet, transaction: Transaction|null, gateway_response: GatewayResponse}
     */
    public function topUp(
        Wallet $wallet,
        int $amount,
        string $gatewayUuid,
        array $paymentData = [],
        string $description = '',
    ): array {
        $paymentService = $this->resolvePaymentService();

        $purchaseRequest = new PurchaseRequest(
            amount: $amount,
            currency: $wallet->currency,
            description: $description ?: "Wallet top-up: {$wallet->public_id}",
            paymentMethodToken: $paymentData['payment_method_token'] ?? null,
            customerId: $paymentData['customer_id'] ?? null,
            customerEmail: $paymentData['customer_email'] ?? null,
            metadata: array_merge($paymentData['metadata'] ?? [], [
                'wallet_uuid'      => $wallet->uuid,
                'wallet_public_id' => $wallet->public_id,
            ]),
        );

        $gatewayResponse = $paymentService->charge($gatewayUuid, $purchaseRequest);

        $walletTransaction = null;

        // For synchronous success (Stripe confirmed, Cash), credit immediately
        if ($gatewayResponse->isSuccessful() && $gatewayResponse->status === \Fleetbase\Ledger\DTO\GatewayResponse::STATUS_SUCCEEDED) {
            // Find the persisted GatewayTransaction
            $gatewayTransaction = \Fleetbase\Ledger\Models\GatewayTransaction::where(
                'gateway_reference_id', $gatewayResponse->gatewayTransactionId
            )->latest()->first();

            $walletTransaction = $this->deposit(
                wallet: $wallet,
                amount: $amount,
                description: $description ?: 'Top-up via payment gateway',
                type: 'deposit',
                options: [
                    'reference'                => $gatewayResponse->gatewayTransactionId,
                    'gateway_transaction_uuid' => $gatewayTransaction?->uuid,
                    'meta'                     => [
                        'gateway_uuid'           => $gatewayUuid,
                        'gateway_transaction_id' => $gatewayResponse->gatewayTransactionId,
                    ],
                ]
            );
        }

        return [
            'wallet'           => $wallet->fresh(),
            'transaction'      => $walletTransaction,
            'gateway_response' => $gatewayResponse,
        ];
    }

    // =========================================================================
    // Driver Earnings & Payouts
    // =========================================================================

    /**
     * Credit earnings to a driver's wallet after order completion.
     *
     * This is the primary method for paying drivers. It credits the driver's
     * wallet and creates an "earning" Transaction record.
     *
     * Double-entry accounting:
     *   DEBIT  Driver Earnings Payable (expense — we owe the driver)
     *   CREDIT Wallet Liability        (liability — the driver's wallet balance)
     *
     * @param Model $driver  The driver model (must have uuid and company_uuid)
     * @param int   $amount  Amount in smallest currency unit (cents)
     * @param array $options Optional: reference (order_uuid), meta
     */
    public function creditEarnings(
        Model $driver,
        int $amount,
        string $currency = 'USD',
        string $description = '',
        array $options = [],
    ): Transaction {
        $wallet = $this->getOrCreateWallet($driver, $currency);

        return $this->deposit(
            wallet: $wallet,
            amount: $amount,
            description: $description ?: 'Earnings credited',
            type: 'earning',
            options: array_merge($options, [
                'subject_uuid' => $driver->uuid,
                'subject_type' => get_class($driver),
            ])
        );
    }

    /**
     * Process a payout from a driver's wallet to an external destination.
     *
     * This represents the driver requesting to withdraw their earnings
     * to a bank account or external payment method.
     *
     * @param Model $driver  The driver model
     * @param int   $amount  Amount in smallest currency unit (cents)
     * @param array $options Optional: reference, meta, destination_account
     *
     * @throws \Exception if wallet has insufficient balance
     */
    public function processPayout(
        Model $driver,
        int $amount,
        string $description = '',
        array $options = [],
    ): Transaction {
        $wallet = $this->getOrCreateWallet($driver, $options['currency'] ?? 'USD');

        return $this->withdraw(
            wallet: $wallet,
            amount: $amount,
            description: $description ?: 'Driver payout',
            type: 'payout',
            options: array_merge($options, [
                'subject_uuid' => $driver->uuid,
                'subject_type' => get_class($driver),
            ])
        );
    }

    // =========================================================================
    // Balance Recalculation
    // =========================================================================

    /**
     * Recalculate and correct a wallet's balance from its transaction history.
     *
     * This is a reconciliation utility. The authoritative balance is always
     * the sum of completed credit transactions minus completed debit transactions.
     *
     * @return int The corrected balance
     */
    public function recalculateBalance(Wallet $wallet): int
    {
        $credits = Transaction::where('owner_uuid', $wallet->uuid)
            ->where('direction', 'credit')
            ->where('status', 'completed')
            ->sum('amount');

        $debits = Transaction::where('owner_uuid', $wallet->uuid)
            ->where('direction', 'debit')
            ->where('status', 'completed')
            ->sum('amount');

        $correctBalance = (int) ($credits - $debits);

        if ($wallet->balance !== $correctBalance) {
            Log::channel('ledger')->warning('Wallet balance mismatch corrected.', [
                'wallet_uuid'      => $wallet->uuid,
                'recorded_balance' => $wallet->balance,
                'correct_balance'  => $correctBalance,
            ]);

            $wallet->update(['balance' => $correctBalance]);
        }

        return $correctBalance;
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    /**
     * Get or create the ledger liability account for a specific wallet.
     *
     * Each wallet has its own dedicated liability account in the chart of accounts.
     * This account represents the amount the company owes to the wallet holder.
     */
    protected function getWalletAccount(Wallet $wallet): Account
    {
        return Account::firstOrCreate(
            [
                'company_uuid' => $wallet->company_uuid,
                'code'         => "WALLET-{$wallet->uuid}",
            ],
            [
                'name'              => "Wallet: {$wallet->public_id}",
                'type'              => 'liability',
                'description'       => "Liability account for wallet {$wallet->public_id}",
                'is_system_account' => true,
                'currency'          => $wallet->currency,
            ]
        );
    }

    /**
     * Get or create the default cash account for a company.
     */
    protected function getDefaultCashAccount(string $companyUuid): Account
    {
        return Account::firstOrCreate(
            [
                'company_uuid' => $companyUuid,
                'code'         => 'CASH-DEFAULT',
            ],
            [
                'name'              => 'Cash',
                'type'              => 'asset',
                'description'       => 'Default cash account',
                'is_system_account' => true,
            ]
        );
    }

    /**
     * Lazily resolve the PaymentService to avoid circular dependency
     * (PaymentService → WalletService → PaymentService).
     */
    protected function resolvePaymentService(): PaymentService
    {
        if (!$this->paymentService) {
            $this->paymentService = app(PaymentService::class);
        }

        return $this->paymentService;
    }
}
