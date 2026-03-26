<?php

namespace Fleetbase\Ledger\Seeders;

use Fleetbase\Ledger\Models\Account;
use Illuminate\Database\Seeder;

/**
 * LedgerSeeder
 *
 * Seeds the default system chart of accounts for every company in the Fleetbase
 * installation. These accounts are the foundational double-entry bookkeeping
 * primitives that all Ledger operations (wallet deposits, invoice payments,
 * driver payouts, gateway settlements, etc.) reference.
 *
 * This seeder is idempotent — it uses `firstOrCreate` so it is safe to run
 * multiple times without creating duplicate accounts.
 *
 * Account code conventions:
 *   1000–1999  Assets
 *   2000–2999  Liabilities
 *   3000–3999  Equity
 *   4000–4999  Revenue
 *   5000–5999  Expenses
 *
 * Usage:
 *   php artisan db:seed --class="Fleetbase\Ledger\Seeds\LedgerSeeder"
 *
 * Or call from a company-provisioning hook:
 *   (new LedgerSeeder())->runForCompany($companyUuid);
 */
class LedgerSeeder extends Seeder
{
    /**
     * The default system accounts to seed.
     * Each entry maps to a row in `ledger_accounts`.
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $defaultAccounts = [
        // -----------------------------------------------------------------------
        // ASSETS (1000–1999)
        // -----------------------------------------------------------------------
        [
            'code'              => 'CASH-DEFAULT',
            'name'              => 'Cash',
            'type'              => 'asset',
            'description'       => 'Primary cash account for all cash receipts and payments.',
            'is_system_account' => true,
        ],
        [
            'code'              => 'BANK-DEFAULT',
            'name'              => 'Bank Account',
            'type'              => 'asset',
            'description'       => 'Primary bank / checking account.',
            'is_system_account' => true,
        ],
        [
            'code'              => 'AR-DEFAULT',
            'name'              => 'Accounts Receivable',
            'type'              => 'asset',
            'description'       => 'Amounts owed to the company by customers for delivered services.',
            'is_system_account' => true,
        ],
        [
            'code'              => 'STRIPE-CLEARING',
            'name'              => 'Stripe Clearing Account',
            'type'              => 'asset',
            'description'       => 'Funds in transit from Stripe before settlement to the bank account.',
            'is_system_account' => true,
        ],
        [
            'code'              => 'GATEWAY-CLEARING',
            'name'              => 'Payment Gateway Clearing',
            'type'              => 'asset',
            'description'       => 'Generic clearing account for payment gateway funds in transit.',
            'is_system_account' => true,
        ],
        [
            'code'              => 'PREPAID-DEFAULT',
            'name'              => 'Prepaid Expenses',
            'type'              => 'asset',
            'description'       => 'Expenses paid in advance (e.g. prepaid insurance, subscriptions).',
            'is_system_account' => true,
        ],

        // -----------------------------------------------------------------------
        // LIABILITIES (2000–2999)
        // -----------------------------------------------------------------------
        [
            'code'              => 'AP-DEFAULT',
            'name'              => 'Accounts Payable',
            'type'              => 'liability',
            'description'       => 'Amounts owed by the company to suppliers and service providers.',
            'is_system_account' => true,
        ],
        [
            'code'              => 'WALLET-POOL',
            'name'              => 'Wallet Liability Pool',
            'type'              => 'liability',
            'description'       => 'Aggregate liability representing all customer and driver wallet balances.',
            'is_system_account' => true,
        ],
        [
            'code'              => 'DRIVER-PAYABLE',
            'name'              => 'Driver Earnings Payable',
            'type'              => 'liability',
            'description'       => 'Earnings accrued to drivers that have not yet been paid out.',
            'is_system_account' => true,
        ],
        [
            'code'              => 'CUSTOMER-CREDIT',
            'name'              => 'Customer Credit Liability',
            'type'              => 'liability',
            'description'       => 'Credit balances held on behalf of customers (refunds, promotions).',
            'is_system_account' => true,
        ],
        [
            'code'              => 'TAX-PAYABLE',
            'name'              => 'Tax Payable',
            'type'              => 'liability',
            'description'       => 'Sales tax and VAT collected from customers, payable to tax authorities.',
            'is_system_account' => true,
        ],
        [
            'code'              => 'STRIPE-FEES-PAYABLE',
            'name'              => 'Stripe Fees Payable',
            'type'              => 'liability',
            'description'       => 'Processing fees charged by Stripe, accrued before settlement.',
            'is_system_account' => true,
        ],
        [
            'code'              => 'DEFERRED-REVENUE',
            'name'              => 'Deferred Revenue',
            'type'              => 'liability',
            'description'       => 'Payments received before the service has been delivered.',
            'is_system_account' => true,
        ],

        // -----------------------------------------------------------------------
        // EQUITY (3000–3999)
        // -----------------------------------------------------------------------
        [
            'code'              => 'EQUITY-DEFAULT',
            'name'              => 'Owner\'s Equity',
            'type'              => 'equity',
            'description'       => 'Residual interest in the company assets after deducting liabilities.',
            'is_system_account' => true,
        ],
        [
            'code'              => 'RETAINED-EARNINGS',
            'name'              => 'Retained Earnings',
            'type'              => 'equity',
            'description'       => 'Cumulative net income retained in the business.',
            'is_system_account' => true,
        ],

        // -----------------------------------------------------------------------
        // REVENUE (4000–4999)
        // -----------------------------------------------------------------------
        [
            'code'              => 'REVENUE-DELIVERY',
            'name'              => 'Delivery Revenue',
            'type'              => 'revenue',
            'description'       => 'Revenue earned from completed delivery orders.',
            'is_system_account' => true,
        ],
        [
            'code'              => 'REVENUE-SERVICE-FEE',
            'name'              => 'Service Fee Revenue',
            'type'              => 'revenue',
            'description'       => 'Platform service fees charged on top of delivery revenue.',
            'is_system_account' => true,
        ],
        [
            'code'              => 'REVENUE-SUBSCRIPTION',
            'name'              => 'Subscription Revenue',
            'type'              => 'revenue',
            'description'       => 'Recurring subscription fees from merchants and partners.',
            'is_system_account' => true,
        ],
        [
            'code'              => 'REVENUE-OTHER',
            'name'              => 'Other Revenue',
            'type'              => 'revenue',
            'description'       => 'Miscellaneous revenue not classified elsewhere.',
            'is_system_account' => true,
        ],

        // -----------------------------------------------------------------------
        // EXPENSES (5000–5999)
        // -----------------------------------------------------------------------
        [
            'code'              => 'EXPENSE-DRIVER-PAYOUT',
            'name'              => 'Driver Payout Expense',
            'type'              => 'expense',
            'description'       => 'Earnings paid out to drivers for completed deliveries.',
            'is_system_account' => true,
        ],
        [
            'code'              => 'EXPENSE-GATEWAY-FEES',
            'name'              => 'Payment Gateway Fees',
            'type'              => 'expense',
            'description'       => 'Transaction processing fees charged by payment gateways (Stripe, QPay, etc.).',
            'is_system_account' => true,
        ],
        [
            'code'              => 'EXPENSE-REFUNDS',
            'name'              => 'Refunds & Chargebacks',
            'type'              => 'expense',
            'description'       => 'Customer refunds and chargeback losses.',
            'is_system_account' => true,
        ],
        [
            'code'              => 'EXPENSE-WALLET',
            'name'              => 'Wallet Expenses',
            'type'              => 'expense',
            'description'       => 'Expenses arising from wallet top-up promotions and adjustments.',
            'is_system_account' => true,
        ],
        [
            'code'              => 'EXPENSE-OTHER',
            'name'              => 'Other Operating Expenses',
            'type'              => 'expense',
            'description'       => 'Miscellaneous operating expenses not classified elsewhere.',
            'is_system_account' => true,
        ],
    ];

    /**
     * Run the seeder for all companies.
     *
     * When called via `php artisan db:seed`, this method seeds accounts for
     * every company currently in the database.
     *
     * @return void
     */
    public function run(): void
    {
        // Resolve the Company model dynamically to avoid a hard dependency on
        // the core-api package at class-load time.
        $companyModel = app('Fleetbase\Models\Company');
        $companies    = $companyModel::all();

        foreach ($companies as $company) {
            $this->runForCompany($company->uuid);
        }

        $this->command?->info(
            sprintf(
                '[Ledger] Seeded %d default accounts for %d companies.',
                count($this->defaultAccounts),
                $companies->count()
            )
        );
    }

    /**
     * Seed default system accounts for a specific company.
     *
     * This method is safe to call from company-provisioning hooks, onboarding
     * flows, or tests. It is idempotent — existing accounts are not modified.
     *
     * public_id is derived deterministically from the company UUID and account
     * code so that bulk provisioning across hundreds of companies never triggers
     * the HasPublicId collision-retry loop. The format is:
     *   account_<first-8-chars-of-sha256(company_uuid + ':' + code)>
     *
     * @param string $companyUuid
     *
     * @return void
     */
    public function runForCompany(string $companyUuid): void
    {
        foreach ($this->defaultAccounts as $accountData) {
            // Derive a stable, collision-free public_id from the company UUID
            // and the account code. This avoids the HasPublicId entropy-collision
            // problem that occurs when many accounts are inserted in rapid
            // succession (same process, same second, same microsecond window).
            $publicId = 'account_' . substr(
                hash('sha256', $companyUuid . ':' . $accountData['code']),
                0,
                10
            );

            Account::firstOrCreate(
                [
                    'company_uuid' => $companyUuid,
                    'code'         => $accountData['code'],
                ],
                array_merge($accountData, [
                    'public_id'    => $publicId,
                    'company_uuid' => $companyUuid,
                    'balance'      => 0,
                    'status'       => 'active',
                ])
            );
        }
    }
}
