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
     * Correct double-entry accounting treatment for a wallet deposit:
     *   DEBIT  Cash / Source Account  (asset increases — money received)
     *   CREDIT Wallet Liability       (liability increases — we owe more to wallet holder)
     *
     * @param Wallet $wallet
     * @param int    $amount      Amount in smallest currency unit (e.g. cents)
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
            // Debit: Cash / source account (asset increases — money received)
            $cashAccount = $options['source_account'] ?? $this->getDefaultCashAccount($wallet->company_uuid);

            // Credit: Wallet liability account (liability increases — we owe more to the wallet holder)
            $walletAccount = $this->getWalletAccount($wallet);

            // DEBIT Cash, CREDIT Wallet Liability
            $this->ledgerService->createJournalEntry(
                $cashAccount,    // debit  — asset increases
                $walletAccount,  // credit — liability increases
                $amount,
                $description ?: "Deposit to wallet {$wallet->public_id}",
                array_merge($options, [
                    'company_uuid' => $wallet->company_uuid,
                    'currency'     => $wallet->currency,
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
     * Correct double-entry accounting treatment for a wallet withdrawal:
     *   DEBIT  Wallet Liability       (liability decreases — we owe less to wallet holder)
     *   CREDIT Cash / Dest Account    (asset decreases — money paid out)
     *
     * @param Wallet $wallet
     * @param int    $amount      Amount in smallest currency unit (e.g. cents)
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
            // Debit: Wallet liability account (liability decreases — we owe less to the wallet holder)
            $walletAccount = $this->getWalletAccount($wallet);

            // Credit: Cash / destination account (asset decreases — money paid out)
            $cashAccount = $options['destination_account'] ?? $this->getDefaultCashAccount($wallet->company_uuid);

            // DEBIT Wallet Liability, CREDIT Cash
            $this->ledgerService->createJournalEntry(
                $walletAccount,  // debit  — liability decreases
                $cashAccount,    // credit — asset decreases
                $amount,
                $description ?: "Withdrawal from wallet {$wallet->public_id}",
                array_merge($options, [
                    'company_uuid' => $wallet->company_uuid,
                    'currency'     => $wallet->currency,
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
     * Correct double-entry accounting treatment for a wallet-to-wallet transfer:
     *   DEBIT  From Wallet Liability  (source liability decreases — we owe less to source holder)
     *   CREDIT To Wallet Liability    (destination liability increases — we owe more to dest holder)
     *
     * @param Wallet $fromWallet
     * @param Wallet $toWallet
     * @param int    $amount      Amount in smallest currency unit (e.g. cents)
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
            // Debit: From wallet liability (source liability decreases — we owe less to source holder)
            $fromAccount = $this->getWalletAccount($fromWallet);

            // Credit: To wallet liability (destination liability increases — we owe more to dest holder)
            $toAccount = $this->getWalletAccount($toWallet);

            // DEBIT From Wallet Liability, CREDIT To Wallet Liability
            $journal = $this->ledgerService->createJournalEntry(
                $fromAccount,  // debit  — source liability decreases
                $toAccount,    // credit — destination liability increases
                $amount,
                $description ?: "Transfer from wallet {$fromWallet->public_id} to {$toWallet->public_id}",
                array_merge($options, [
                    'company_uuid' => $fromWallet->company_uuid,
                    'currency'     => $fromWallet->currency,
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
     * Get or create the ledger liability account for a wallet.
     *
     * Each wallet has its own dedicated liability account in the chart of accounts.
     * This account represents the amount the company owes to the wallet holder.
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
     * Get or create the default cash account for a company.
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

}

