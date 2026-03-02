<?php

namespace Fleetbase\Ledger\Console\Commands;

use Fleetbase\Ledger\Seeders\LedgerSeeder;
use Fleetbase\Ledger\Services\WalletService;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Illuminate\Console\Command;

/**
 * ProvisionLedgerDefaults.
 *
 * Backfills default chart-of-accounts and system wallets for all companies
 * (or a specific one) that were registered before automatic provisioning was
 * enabled, and provisions personal wallets for all existing driver/customer
 * users. Safe to run multiple times — all operations are idempotent.
 *
 * Usage:
 *   php artisan ledger:provision                     # all companies + all users
 *   php artisan ledger:provision --company=<uuid>    # one company + its users
 *   php artisan ledger:provision --accounts-only     # skip wallets
 *   php artisan ledger:provision --wallets-only      # skip accounts
 */
class ProvisionLedgerDefaults extends Command
{
    protected $signature = 'ledger:provision
                            {--company= : UUID of a specific company to provision}
                            {--accounts-only : Only provision default chart of accounts, skip wallets}
                            {--wallets-only  : Only provision system wallets, skip accounts}';

    protected $description = 'Provision default accounts and wallets for all companies and users (or a specific company).';

    public function handle(WalletService $walletService): int
    {
        $skipAccounts = (bool) $this->option('wallets-only');
        $skipWallets  = (bool) $this->option('accounts-only');
        $companyUuid  = $this->option('company');

        // ── Companies ────────────────────────────────────────────────────────
        $companies = $companyUuid
            ? Company::where('uuid', $companyUuid)->get()
            : Company::all();

        if ($companies->isEmpty()) {
            $this->warn('[Ledger] No companies found to provision.');
            return self::SUCCESS;
        }

        // Require the seeder file directly — it lives in the package's
        // server/seeders/ directory which may not be in the host app's
        // autoloader in local path-repository dev setups.
        $seederPath = realpath(__DIR__ . '/../../../seeders/LedgerSeeder.php');
        if ($seederPath && file_exists($seederPath)) {
            require_once $seederPath;
        }
        $seeder              = new LedgerSeeder();
        $accountsProvisioned = 0;
        $companyWallets      = 0;
        $userWallets         = 0;
        $errors              = 0;

        $this->info('[Ledger] Provisioning ' . $companies->count() . ' company/companies...');
        $bar = $this->output->createProgressBar($companies->count());
        $bar->start();

        foreach ($companies as $company) {
            // Seed default chart of accounts
            if (!$skipAccounts) {
                try {
                    $seeder->runForCompany($company->uuid);
                    $accountsProvisioned++;
                } catch (\Throwable $e) {
                    $this->newLine();
                    $this->error("[Ledger] Accounts failed for company {$company->uuid}: " . $e->getMessage());
                    $errors++;
                }
            }

            // Provision company system wallets (Operating, Revenue, Payout Reserve, Refund Reserve)
            if (!$skipWallets) {
                try {
                    $walletService->provisionCompanyWallets($company);
                    $companyWallets++;
                } catch (\Throwable $e) {
                    $this->newLine();
                    $this->error("[Ledger] Company wallets failed for {$company->uuid}: " . $e->getMessage());
                    $errors++;
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

         // ── Users (personal wallets for all users) ───────────────────────────
        if (!$skipWallets) {
            $usersQuery = User::whereNotNull('company_uuid');
            if ($companyUuid) {
                $usersQuery->where('company_uuid', $companyUuid);
            }
            $users = $usersQuery->get();
            if ($users->isNotEmpty()) {
                $this->info('[Ledger] Provisioning personal wallets for ' . $users->count() . ' user(s)...');
                $userBar = $this->output->createProgressBar($users->count());
                $userBar->start();
                foreach ($users as $user) {
                    try {
                        $walletService->provisionUserWallet($user);
                        $userWallets++;
                    } catch (\Throwable $e) {
                        $this->newLine();
                        $this->error("[Ledger] User wallet failed for {$user->uuid}: " . $e->getMessage());
                        $errors++;
                    }
                    $userBar->advance();
                }
                $userBar->finish();
                $this->newLine(2);
            } else {
                $this->info('[Ledger] No users found to provision wallets for.');
            }
        }

        // ── Summary ──────────────────────────────────────────────────────────
        if (!$skipAccounts) {
            $this->info("[Ledger] Chart of accounts provisioned for {$accountsProvisioned} company/companies.");
        }

        if (!$skipWallets) {
            $this->info("[Ledger] System wallets provisioned for {$companyWallets} company/companies.");
            $this->info("[Ledger] Personal wallets provisioned for {$userWallets} user(s).");
        }

        if ($errors > 0) {
            $this->warn("[Ledger] {$errors} error(s) occurred — check logs for details.");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
