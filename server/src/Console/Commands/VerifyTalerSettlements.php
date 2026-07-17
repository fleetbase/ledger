<?php

namespace Fleetbase\Ledger\Console\Commands;

use Fleetbase\Ledger\DTO\GatewayResponse;
use Fleetbase\Ledger\Models\Gateway;
use Fleetbase\Ledger\Models\GatewayTransaction;
use Fleetbase\Ledger\PaymentGatewayManager;
use Illuminate\Console\Command;

class VerifyTalerSettlements extends Command
{
    protected $signature = 'ledger:taler:verify-settlements
                            {--company= : Limit to a company UUID}
                            {--gateway= : Limit to a gateway UUID or public ID}
                            {--limit=100 : Maximum gateway transactions to inspect}';

    protected $description = 'Verify GNU Taler order settlement/wire status for paid Ledger gateway transactions.';

    public function handle(PaymentGatewayManager $gatewayManager): int
    {
        $gatewayQuery = Gateway::where('driver', 'taler')->where('status', 'active');

        if ($companyUuid = $this->option('company')) {
            $gatewayQuery->where('company_uuid', $companyUuid);
        }

        if ($gatewayId = $this->option('gateway')) {
            $gatewayQuery->where(fn ($q) => $q->where('uuid', $gatewayId)->orWhere('public_id', $gatewayId));
        }

        $gateways = $gatewayQuery->get();

        if ($gateways->isEmpty()) {
            $this->warn('[Ledger/Taler] No active Taler gateways found.');

            return self::SUCCESS;
        }

        $checked = 0;
        $errors  = 0;

        foreach ($gateways as $gateway) {
            $driver = $gatewayManager->driver($gateway->driver)->initialize($gateway->decryptedConfig(), $gateway->is_sandbox);

            if (!method_exists($driver, 'fetchOrderStatus')) {
                continue;
            }

            $transactions = GatewayTransaction::where('gateway_uuid', $gateway->uuid)
                ->whereIn('event_type', [GatewayResponse::EVENT_PAYMENT_SUCCEEDED, GatewayResponse::EVENT_PAYMENT_PENDING])
                ->whereNotNull('gateway_reference_id')
                ->orderBy('created_at')
                ->limit((int) $this->option('limit'))
                ->get();

            foreach ($transactions as $transaction) {
                try {
                    $result = $driver->fetchOrderStatus($transaction->gateway_reference_id);
                    $data   = $result['data'] ?? [];
                    $wired  = (bool) data_get($data, 'wired', false);
                    $status = data_get($data, 'order_status');

                    $transaction->reconciliation_status = $wired
                        ? 'wire_reconciled'
                        : ($status === 'paid' ? 'settlement_checked' : 'not_settled');
                    $transaction->reconciliation_checked_at = now();
                    $transaction->reconciliation_data       = [
                        'http_status'      => $result['http_status'] ?? null,
                        'order_status'     => $status,
                        'wired'            => $wired,
                        'deposit_total'    => data_get($data, 'deposit_total'),
                        'wire_transfer_id' => data_get($data, 'wire_transfer_id') ?? data_get($data, 'wire_transfer_subject'),
                        'raw'              => $data,
                    ];
                    $transaction->save();
                    $checked++;
                } catch (\Throwable $e) {
                    $transaction->reconciliation_status     = 'error';
                    $transaction->reconciliation_checked_at = now();
                    $transaction->reconciliation_data       = [
                        'error' => $e->getMessage(),
                    ];
                    $transaction->save();
                    $errors++;
                }
            }
        }

        $this->info("[Ledger/Taler] Settlement verification complete. Checked {$checked}; errors {$errors}.");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
