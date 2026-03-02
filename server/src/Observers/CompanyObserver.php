<?php

namespace Fleetbase\Ledger\Observers;

use Fleetbase\Ledger\Seeds\LedgerSeeder;
use Fleetbase\Ledger\Services\WalletService;
use Fleetbase\Models\Company;
use Illuminate\Support\Facades\Log;

/**
 * CompanyObserver.
 *
 * Listens on the core Company model and automatically provisions Ledger
 * resources whenever a new company is registered:
 *
 *   1. Default chart of accounts (via LedgerSeeder::runForCompany)
 *   2. Company system wallets (Operating, Revenue, Payout Reserve, Refund Reserve)
 *
 * Each step is independently try/caught so a failure in one does not prevent
 * the other from running, and neither failure will abort the company save.
 */
class CompanyObserver
{
    public function __construct(protected WalletService $walletService) {}

    /**
     * Handle the Company "created" event.
     */
    public function created(Company $company): void
    {
        // 1. Seed the default chart of accounts for this company
        try {
            app(LedgerSeeder::class)->runForCompany($company->uuid);
        } catch (\Throwable $e) {
            Log::error('[Ledger] Failed to seed default accounts for company ' . $company->uuid . ': ' . $e->getMessage());
        }

        // 2. Provision the company system wallets
        try {
            $this->walletService->provisionCompanyWallets($company);
        } catch (\Throwable $e) {
            Log::error('[Ledger] Failed to provision wallets for company ' . $company->uuid . ': ' . $e->getMessage());
        }
    }
}
