<?php

namespace Fleetbase\Ledger\Console\Commands;

use Fleetbase\Ledger\Services\TalerRefundVerificationService;
use Illuminate\Console\Command;

class VerifyTalerRefunds extends Command
{
    protected $signature = 'ledger:taler:verify-refunds
                            {--company= : Limit to a company UUID}
                            {--gateway= : Limit to a gateway UUID or public ID}
                            {--refund= : Limit to a refund gateway transaction UUID, public ID, or gateway reference}
                            {--limit=100 : Maximum refund transactions to inspect}';

    protected $description = 'Verify GNU Taler pending refund wallet acceptance and update invoice refund state.';

    public function handle(TalerRefundVerificationService $verifier): int
    {
        $summary = $verifier->verifyPending([
            'company' => $this->option('company'),
            'gateway' => $this->option('gateway'),
            'refund'  => $this->option('refund'),
            'limit'   => (int) $this->option('limit'),
        ]);

        $this->info(sprintf(
            '[Ledger/Taler] Refund verification complete. Checked %d; accepted %d; pending %d; errors %d.',
            $summary['checked'],
            $summary['accepted'],
            $summary['pending'],
            $summary['errors'],
        ));

        foreach ($summary['results'] as $result) {
            $this->line(sprintf(
                '- %s: %s%s',
                $result['id'] ?? 'refund',
                $result['status'] ?? 'unknown',
                isset($result['message']) ? ' - ' . $result['message'] : ''
            ));
        }

        return ($summary['errors'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
