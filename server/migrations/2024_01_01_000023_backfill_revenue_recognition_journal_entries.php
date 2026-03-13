<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Backfill revenue-recognition journal entries for invoices that were
     * created before InvoiceController::onAfterCreate started calling
     * InvoiceService::recogniseRevenue() (added in commit e285aed, Mar 10 2026).
     *
     * Without these entries the Income Statement has nothing to sum and the
     * Overview widget shows $0.00 revenue even for companies with paid invoices.
     *
     * Logic:
     *   For every invoice that:
     *     - has total_amount > 0
     *     - does NOT already have a journal entry of type 'revenue_recognition'
     *       linked via meta->invoice_uuid
     *   we insert:
     *     DEBIT  AR-DEFAULT  (accounts receivable — asset increases)
     *     CREDIT REV-DEFAULT (sales revenue — revenue increases)
     *
     * This is idempotent: the WHERE NOT EXISTS guard prevents duplicates if
     * the migration is run more than once.
     */
    public function up(): void
    {
        // Ensure all system accounts have status = 'active' first
        // (defensive — migration 000022 should have already done this)
        DB::table('ledger_accounts')
            ->whereNull('status')
            ->where('is_system_account', true)
            ->update(['status' => 'active']);

        // Fetch invoices that need backfilling
        $invoices = DB::table('ledger_invoices')
            ->whereNull('deleted_at')
            ->where('total_amount', '>', 0)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('ledger_journals')
                    ->whereNull('ledger_journals.deleted_at')
                    ->where('ledger_journals.type', 'revenue_recognition')
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(ledger_journals.meta, '$.invoice_uuid')) = ledger_invoices.uuid");
            })
            ->get(['uuid', 'public_id', 'company_uuid', 'total_amount', 'currency', 'number', 'created_at']);

        foreach ($invoices as $invoice) {
            // Resolve or create the AR and Revenue accounts for this company
            $arAccount = $this->resolveAccount($invoice->company_uuid, 'AR-DEFAULT', 'asset', 'Accounts Receivable');
            $revAccount = $this->resolveAccount($invoice->company_uuid, 'REV-DEFAULT', 'revenue', 'Sales Revenue');

            if (!$arAccount || !$revAccount) {
                continue;
            }

            $now       = now()->toDateTimeString();
            $entryDate = $invoice->created_at
                ? substr($invoice->created_at, 0, 10)   // use invoice creation date
                : now()->toDateString();

            // Count existing entries for this company to generate a sequential number
            $count  = DB::table('ledger_journals')
                ->where('company_uuid', $invoice->company_uuid)
                ->count() + 1;
            $number = 'JE-' . str_pad($count, 5, '0', STR_PAD_LEFT);

            $uuid     = (string) Str::uuid();
            $publicId = 'journal_' . Str::random(10);

            DB::table('ledger_journals')->insert([
                'uuid'                => $uuid,
                'public_id'           => $publicId,
                'company_uuid'        => $invoice->company_uuid,
                'debit_account_uuid'  => $arAccount->uuid,
                'credit_account_uuid' => $revAccount->uuid,
                'number'              => $number,
                'type'                => 'revenue_recognition',
                'status'              => 'posted',
                'amount'              => (int) $invoice->total_amount,
                'currency'            => $invoice->currency ?? 'USD',
                'description'         => "Revenue recognition for invoice {$invoice->number} [backfilled]",
                'memo'                => "Revenue recognition for invoice {$invoice->number} [backfilled]",
                'is_system_entry'     => 1,
                'entry_date'          => $entryDate,
                'meta'                => json_encode([
                    'invoice_uuid'  => $invoice->uuid,
                    'backfilled'    => true,
                ]),
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);

            // Refresh cached balances on both accounts
            $this->refreshAccountBalance($arAccount->uuid);
            $this->refreshAccountBalance($revAccount->uuid);
        }
    }

    public function down(): void
    {
        // Remove only the backfilled entries so a rollback is clean
        DB::table('ledger_journals')
            ->where('type', 'revenue_recognition')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.backfilled')) = 'true'")
            ->delete();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function resolveAccount(string $companyUuid, string $code, string $type, string $name): ?object
    {
        $account = DB::table('ledger_accounts')
            ->where('company_uuid', $companyUuid)
            ->where('code', $code)
            ->whereNull('deleted_at')
            ->first();

        if ($account) {
            // Heal status if NULL
            if ($account->status === null) {
                DB::table('ledger_accounts')
                    ->where('uuid', $account->uuid)
                    ->update(['status' => 'active']);
                $account->status = 'active';
            }
            return $account;
        }

        // Create the system account if it doesn't exist yet
        $uuid     = (string) Str::uuid();
        $publicId = 'account_' . substr(hash('sha256', $companyUuid . ':' . $code), 0, 10);
        $now      = now()->toDateTimeString();

        DB::table('ledger_accounts')->insert([
            'uuid'              => $uuid,
            'public_id'         => $publicId,
            'company_uuid'      => $companyUuid,
            'code'              => $code,
            'name'              => $name,
            'type'              => $type,
            'description'       => $name,
            'is_system_account' => 1,
            'balance'           => 0,
            'currency'          => 'USD',
            'status'            => 'active',
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        return DB::table('ledger_accounts')->where('uuid', $uuid)->first();
    }

    private function refreshAccountBalance(string $accountUuid): void
    {
        $debits  = (int) DB::table('ledger_journals')
            ->whereNull('deleted_at')
            ->where('debit_account_uuid', $accountUuid)
            ->sum('amount');

        $credits = (int) DB::table('ledger_journals')
            ->whereNull('deleted_at')
            ->where('credit_account_uuid', $accountUuid)
            ->sum('amount');

        $account = DB::table('ledger_accounts')->where('uuid', $accountUuid)->first();
        if (!$account) {
            return;
        }

        // asset/expense: debit-normal; liability/equity/revenue: credit-normal
        $balance = in_array($account->type, ['asset', 'expense'])
            ? $debits - $credits
            : $credits - $debits;

        DB::table('ledger_accounts')
            ->where('uuid', $accountUuid)
            ->update(['balance' => $balance]);
    }
};
