<?php

namespace Fleetbase\Ledger\Console\Commands;

use Carbon\Carbon;
use Fleetbase\Ledger\Models\PayFileSchedule;
use Fleetbase\Ledger\Services\PayFileGeneratorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Generate scheduled pay files for due schedules.
 *
 * NOT auto-registered in Kernel — operator must explicitly enable.
 * To run on a schedule, add to App\Console\Kernel::schedule():
 *   $schedule->command('pay-files:generate-scheduled')->daily();
 *
 * Or invoke manually: php artisan pay-files:generate-scheduled
 */
class GenerateScheduledPayFiles extends Command
{
    protected $signature = 'pay-files:generate-scheduled
                            {--dry-run : Show what would be generated without executing}';

    protected $description = 'Generate pay files for all due schedules. Does NOT mark invoices paid.';

    public function handle(): int
    {
        $dueSchedules = PayFileSchedule::active()->dueForRun()->get();

        if ($dueSchedules->isEmpty()) {
            $this->info('No schedules are due for run.');
            return self::SUCCESS;
        }

        $this->info("Found {$dueSchedules->count()} schedule(s) due for run.");

        $generator = app(PayFileGeneratorService::class);

        foreach ($dueSchedules as $schedule) {
            $start = $schedule->last_run_at ?? now()->subDays(30);
            $end = now();

            $this->line("Schedule [{$schedule->name}]: period {$start} → {$end}, format={$schedule->format}");

            if ($this->option('dry-run')) {
                continue;
            }

            try {
                $payFile = $generator->generate(
                    $schedule->company_uuid,
                    $schedule->format,
                    Carbon::parse($start),
                    Carbon::parse($end)
                );

                $schedule->update([
                    'last_run_at' => now(),
                    'next_run_at' => $schedule->calculateNextRun(now()),
                ]);

                $this->info("  → Generated PayFile {$payFile->public_id} with {$payFile->record_count} invoices, total \${$payFile->total_amount}");
            } catch (\Throwable $e) {
                $this->error("  → Failed: {$e->getMessage()}");
                Log::error('Scheduled pay file generation failed', [
                    'schedule_uuid' => $schedule->uuid,
                    'error'         => $e->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }
}
