<?php

namespace Fleetbase\Ledger\Services;

use Fleetbase\Ledger\Models\Account;
use Fleetbase\Ledger\Models\Wallet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class WalletService
{
    /**
     * The ledger service instance.
     *
     * @var LedgerService
     */
    protected LedgerService $ledgerService;

    /**
     * Create a new WalletService instance.
     *
     * @param LedgerService $ledgerService
     */
    public function __construct(LedgerService $ledgerService)
    {
        $this->ledgerService = $ledgerService;
    }

    /**
     * Get or create a wallet for a subject.
     *
     * @param Model  $subject
     * @param string $currency
     *
     * @return Wallet
     */
    public function getOrCreateWallet(Model $subject, string $currency = 'USD'): Wallet
    {
        return Wallet::firstOrCreate(
            [
                'subject_uuid' => $subject->uuid,
                'subject_type' => get_class($subject),
            ],
            [
                'company_uuid' => $subject->company_uuid ?? session('company'),
                'currency'     => $currency,
                'balance'      => 0,
                'status'       => 'active',
            ]
        );
    }

    /**
     * Deposit funds into a wallet.
     *
     * @param Wallet $wallet
     * @param int    $amount
     * @param string $description
     * @param array  $options
     *
     * @return Wallet
     */
    public function deposit(Wallet $wallet, int $amount, string $description = '', array $options = []): Wallet
    {
        if (!$wallet->isActive()) {
            throw new \Exception('Wallet is not active');
        }

        return DB::transaction(function () use ($wallet, $amount, $description, $options) {
            // Get or create the wallet liability account
            $walletAccount = $this->getWalletAccount($wallet);

            // Get the source account (e.g., cash or bank account)
            $sourceAccount = $options['source_account'] ?? $this->getDefaultCashAccount($wallet->company_uuid);

            // Create journal entry: Debit Wallet Liability, Credit Source Account
            $this->ledgerService->createJournalEntry(
                $walletAccount,
                $sourceAccount,
                $amount,
                $description ?: "Deposit to wallet {$wallet->public_id}",
                array_merge($options, [
                    'company_uuid' => $wallet->company_uuid,
                    'type'         => 'wallet_deposit',
                ])
            );

            // Update wallet balance
            $wallet->balance += $amount;
            $wallet->save();

            return $wallet;
        });
    }

    /**
     * Withdraw funds from a wallet.
     *
     * @param Wallet $wallet
     * @param int    $amount
     * @param string $description
     * @param array  $options
     *
     * @return Wallet
     */
    public function withdraw(Wallet $wallet, int $amount, string $description = '', array $options = []): Wallet
    {
        if (!$wallet->isActive()) {
            throw new \Exception('Wallet is not active');
        }

        if (!$wallet->hasSufficientBalance($amount)) {
            throw new \Exception('Insufficient wallet balance');
        }

        return DB::transaction(function () use ($wallet, $amount, $description, $options) {
            // Get or create the wallet liability account
            $walletAccount = $this->getWalletAccount($wallet);

            // Get the destination account (e.g., expense or cash account)
            $destinationAccount = $options['destination_account'] ?? $this->getDefaultExpenseAccount($wallet->company_uuid);

            // Create journal entry: Debit Destination Account, Credit Wallet Liability
            $this->ledgerService->createJournalEntry(
                $destinationAccount,
                $walletAccount,
                $amount,
                $description ?: "Withdrawal from wallet {$wallet->public_id}",
                array_merge($options, [
                    'company_uuid' => $wallet->company_uuid,
                    'type'         => 'wallet_withdrawal',
                ])
            );

            // Update wallet balance
            $wallet->balance -= $amount;
            $wallet->save();

            return $wallet;
        });
    }

    /**
     * Transfer funds between two wallets.
     *
     * @param Wallet $fromWallet
     * @param Wallet $toWallet
     * @param int    $amount
     * @param string $description
     * @param array  $options
     *
     * @return array
     */
    public function transfer(Wallet $fromWallet, Wallet $toWallet, int $amount, string $description = '', array $options = []): array
    {
        if (!$fromWallet->isActive() || !$toWallet->isActive()) {
            throw new \Exception('One or both wallets are not active');
        }

        if (!$fromWallet->hasSufficientBalance($amount)) {
            throw new \Exception('Insufficient balance in source wallet');
        }

        return DB::transaction(function () use ($fromWallet, $toWallet, $amount, $description, $options) {
            // Get wallet accounts
            $fromAccount = $this->getWalletAccount($fromWallet);
            $toAccount   = $this->getWalletAccount($toWallet);

            // Create journal entry: Debit To Wallet, Credit From Wallet
            $journal = $this->ledgerService->createJournalEntry(
                $toAccount,
                $fromAccount,
                $amount,
                $description ?: "Transfer from {$fromWallet->public_id} to {$toWallet->public_id}",
                array_merge($options, [
                    'company_uuid' => $fromWallet->company_uuid,
                    'type'         => 'wallet_transfer',
                ])
            );

            // Update wallet balances
            $fromWallet->balance -= $amount;
            $fromWallet->save();

            $toWallet->balance += $amount;
            $toWallet->save();

            return [
                'from_wallet' => $fromWallet,
                'to_wallet'   => $toWallet,
                'journal'     => $journal,
            ];
        });
    }

    /**
     * Get or create the ledger account for a wallet.
     *
     * @param Wallet $wallet
     *
     * @return Account
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
     * Get the default cash account.
     *
     * @param string $companyUuid
     *
     * @return Account
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
     * Get the default expense account.
     *
     * @param string $companyUuid
     *
     * @return Account
     */
    protected function getDefaultExpenseAccount(string $companyUuid): Account
    {
        return Account::firstOrCreate(
            [
                'company_uuid' => $companyUuid,
                'code'         => 'EXPENSE-WALLET',
            ],
            [
                'name'              => 'Wallet Expenses',
                'type'              => 'expense',
                'description'       => 'Expenses from wallet withdrawals',
                'is_system_account' => true,
            ]
        );
    }
}
