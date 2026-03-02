<?php

namespace Fleetbase\Ledger\Console\Commands;

use Fleetbase\Ledger\Seeds\LedgerSeeder;
use Fleetbase\Ledger\Services\WalletService;
use Illuminate\Console\Command;

/**
 * ProvisionLedgerDefaults.
 *
 * Backfills default chart-of-accounts and system wallets for all companies
 * (or a specific one) that were registered before automatic provisioning was
 * enabled. Safe to run multiple times — all operations are idempotent.
 *
 * Usage:
 *   php artisan ledger:provision                     # all companies
 *   php artisan ledger:provision --company=<uuid>    # one company
 *   php artisan ledger:provision --accounts-only     # skip wallets
 *   php artisan ledger:provision --wallets-only      # skip accounts
 */
class ProvisionLedgerDefaults extends Command
{
    protected $signature = 'ledger:provision
                            {--company= : UUID of a specific company to provision}
                            {--accounts-only : Only provision default chart of accounts, skip wallets}
                            {--wallets-only  : Only provision system wallets, skip accounts}';

    protected $description = 'Provision default accounts and wallets for all companies (or a specific one).';

    public function handle(WalletService $walletService): int
    {
        $companyModel = app('Fleetbase\\Models\\Company');
        $companies    = $this->option('company')
            ? $companyModel::where('uuid', $this->option('company'))->get()
            : $companyModel::all();

        if ($companies->isEmpty()) {
            $this->warn('[Ledger] No companies found to provision.');
            return self::SUCCESS;
        }

        $skipAccounts = (bool) $this->option('wallets-only');
        $skipWallets  = (bool) $this->option('accounts-only');
        $seeder       = app(LedgerSeeder::class);
        $bar          = $this->output->createProgressBar($companies->count());

        $bar->start();

        $accountsProvisioned = 0;
        $walletsProvisioned  = 0;
        $errors              = 0;

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

            // Provision company system wallets
            if (!$skipWallets) {
                try {
                    $walletService->provisionCompanyWallets($company);
                    $walletsProvisioned++;
                } catch (\Throwable $e) {
                    $this->newLine();
                    $this->error("[Ledger] Wallets failed for company {$company->uuid}: " . $e->getMessage());
                    $errors++;
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if (!$skipAccounts) {
            $this->info("[Ledger] Chart of accounts provisioned for {$accountsProvisioned} companies.");
        }

        if (!$skipWallets) {
            $this->info("[Ledger] System wallets provisioned for {$walletsProvisioned} companies.");
        }

        if ($errors > 0) {
            $this->warn("[Ledger] {$errors} error(s) occurred — check logs for details.");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
