<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Heal any system accounts that were created before the status column
     * was explicitly set in InvoiceService::getCashAccount / getARAccount /
     * getRevenueAccount.
     *
     * Prior to commit c56ab09, those helpers used firstOrCreate without
     * passing `status`, so the value depended entirely on the DB column
     * default.  In some environments (e.g. when the table was created via
     * a migration that ran before the DEFAULT was present, or when the
     * account was inserted by a seeder that omitted the column) the row
     * ended up with status = NULL.
     *
     * getIncomeStatement queries WHERE status = 'active', so NULL-status
     * revenue accounts are silently excluded and the overview widget shows
     * $0.00 revenue even when journal entries exist.
     *
     * This migration is idempotent: it only updates rows where status IS
     * NULL and is_system_account = 1, leaving any user-managed accounts
     * (which may legitimately be inactive) untouched.
     */
    public function up(): void
    {
        DB::table('ledger_accounts')
            ->whereNull('status')
            ->where('is_system_account', true)
            ->update(['status' => 'active']);
    }

    /**
     * There is no meaningful rollback for a data-healing migration.
     * Reverting to NULL would re-introduce the $0 revenue bug.
     */
    public function down(): void
    {
        // intentionally left blank
    }
};
