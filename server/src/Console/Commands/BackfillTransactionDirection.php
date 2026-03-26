<?php

namespace Fleetbase\Ledger\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * BackfillTransactionDirection.
 *
 * Sets the `direction` column on existing `transactions` rows that have
 * a NULL direction. Direction is derived from the transaction `type`:
 *
 *   credit  → purchase, deposit, earning, transfer_in, topup, payment
 *   debit   → refund, withdrawal, payout, fee, transfer_out, chargeback
 *
 * Any type not in the explicit map defaults to 'credit'.
 *
 * Usage:
 *   php artisan ledger:backfill-direction
 *   php artisan ledger:backfill-direction --chunk=500
 */
class BackfillTransactionDirection extends Command
{
    protected $signature = 'ledger:backfill-direction
                            {--chunk=250 : Number of rows to process per batch}';

    protected $description = 'Backfill the direction (credit/debit) column on existing transaction rows';

    /**
     * Transaction types that represent money going OUT (debit).
     */
    private const DEBIT_TYPES = [
        'refund',
        'withdrawal',
        'payout',
        'fee',
        'transfer_out',
        'chargeback',
        'reversal',
        'void',
    ];

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');

        $total = DB::table('transactions')->whereNull('direction')->count();

        if ($total === 0) {
            $this->info('[Ledger] All transactions already have a direction set.');

            return self::SUCCESS;
        }

        $this->info("[Ledger] Backfilling direction on {$total} transaction(s)...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $processed = 0;

        DB::table('transactions')
            ->whereNull('direction')
            ->orderBy('id')
            ->chunk($chunk, function ($rows) use ($bar, &$processed) {
                foreach ($rows as $row) {
                    $direction = in_array(strtolower((string) $row->type), self::DEBIT_TYPES, true)
                        ? 'debit'
                        : 'credit';

                    DB::table('transactions')
                        ->where('id', $row->id)
                        ->update(['direction' => $direction]);

                    $processed++;
                }
                $bar->advance(count($rows));
            });

        $bar->finish();
        $this->newLine();
        $this->info("[Ledger] Done — {$processed} transaction(s) updated.");

        return self::SUCCESS;
    }
}
