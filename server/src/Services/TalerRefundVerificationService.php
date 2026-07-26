<?php

namespace Fleetbase\Ledger\Services;

use Fleetbase\Ledger\Models\Gateway;
use Fleetbase\Ledger\Models\GatewayTransaction;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\PaymentGatewayManager;
use Illuminate\Support\Facades\Log;

class TalerRefundVerificationService
{
    public function __construct(protected PaymentGatewayManager $gatewayManager)
    {
    }

    public function verifyPending(array $options = []): array
    {
        $gatewayQuery = Gateway::where('driver', 'taler')->where('status', 'active');

        if ($companyUuid = data_get($options, 'company')) {
            $gatewayQuery->where('company_uuid', $companyUuid);
        }

        if ($gatewayId = data_get($options, 'gateway')) {
            $gatewayQuery->where(fn ($q) => $q->where('uuid', $gatewayId)->orWhere('public_id', $gatewayId));
        }

        $checked  = 0;
        $accepted = 0;
        $pending  = 0;
        $errors   = 0;
        $results  = [];
        $limit    = (int) data_get($options, 'limit', 100);

        foreach ($gatewayQuery->get() as $gateway) {
            $query = $this->pendingRefundQuery($gateway);

            if ($refundId = data_get($options, 'refund')) {
                $query->where(fn ($q) => $q->where('uuid', $refundId)->orWhere('public_id', $refundId)->orWhere('gateway_reference_id', $refundId));
            }

            foreach ($query->limit($limit)->get() as $refund) {
                $result    = $this->verifyRefund($refund, $gateway);
                $results[] = $result;
                $checked++;

                if (($result['status'] ?? null) === 'accepted') {
                    $accepted++;
                } elseif (($result['status'] ?? null) === 'pending') {
                    $pending++;
                } else {
                    $errors++;
                }
            }
        }

        return compact('checked', 'accepted', 'pending', 'errors', 'results');
    }

    public function verifyRefund(GatewayTransaction $refund, ?Gateway $gateway = null): array
    {
        $gateway ??= $refund->gateway;

        if (!$gateway || $gateway->driver !== 'taler') {
            return $this->markVerificationError($refund, 'Refund transaction is not attached to a GNU Taler gateway.');
        }

        $orderId = $this->resolveOrderId($refund);

        if (!$orderId) {
            return $this->markVerificationError($refund, 'Unable to resolve original Taler order id for refund.');
        }

        try {
            $driver = $this->gatewayManager->driver('taler')->initialize($gateway->decryptedConfig(), $gateway->is_sandbox);

            if (!method_exists($driver, 'fetchRefundStatus')) {
                return $this->markVerificationError($refund, 'GNU Taler driver does not support refund status polling.');
            }

            $targetAmount = $this->cumulativeRefundAmountForOrder($refund, $orderId);
            $result       = $driver->fetchRefundStatus($orderId, $targetAmount, $refund->currency);
            $data         = $result['data'] ?? [];
            $accepted     = $this->isRefundAccepted($data, $targetAmount, $refund->currency);

            $this->storeVerificationResult($refund, $result, $accepted);
            $invoice = $this->resolveInvoice($refund);

            if ($invoice) {
                $this->recalculateInvoiceRefundState($invoice);
            }

            return [
                'id'            => $refund->public_id ?? $refund->uuid,
                'order_id'      => $orderId,
                'status'        => $accepted ? 'accepted' : 'pending',
                'http_status'   => $result['http_status'] ?? null,
                'target_amount' => $targetAmount,
                'message'       => $accepted ? 'Taler refund was accepted by the wallet.' : 'Taler refund is still pending wallet acceptance.',
            ];
        } catch (\Throwable $e) {
            Log::channel('ledger')->warning('[Ledger/Taler] Refund verification failed.', [
                'gateway_transaction_uuid' => $refund->uuid,
                'error'                    => $e->getMessage(),
            ]);

            return $this->markVerificationError($refund, $e->getMessage());
        }
    }

    protected function pendingRefundQuery(Gateway $gateway)
    {
        return GatewayTransaction::query()
            ->where('company_uuid', $gateway->company_uuid)
            ->where('gateway_uuid', $gateway->uuid)
            ->where('type', 'refund')
            ->where('status', 'succeeded')
            ->whereNull('refund_accepted_at')
            ->where(function ($query) {
                $query->whereNull('refund_status')
                    ->orWhereIn('refund_status', ['wallet_uri_returned', 'backend_approved', 'pending_wallet_acceptance', 'succeeded']);
            })
            ->orderBy('created_at');
    }

    protected function storeVerificationResult(GatewayTransaction $refund, array $result, bool $accepted): void
    {
        $raw = $refund->raw_response ?? [];
        data_set($raw, 'refund_verification', [
            'checked_at'     => now()->toISOString(),
            'http_status'    => $result['http_status'] ?? null,
            'refund_pending' => data_get($result, 'data.refund_pending'),
            'refund_amount'  => data_get($result, 'data.refund_amount'),
            'refund_taken'   => data_get($result, 'data.refund_taken'),
            'order_status'   => data_get($result, 'data.order_status'),
        ]);

        if ($accepted) {
            data_set($raw, 'data.wallet_status', 'accepted');
            data_set($raw, 'data.refund_status', 'accepted');
        }

        $refund->refund_status      = $accepted ? 'accepted' : 'pending_wallet_acceptance';
        $refund->refund_accepted_at = $accepted ? now() : null;
        $refund->raw_response       = $raw;
        $refund->save();
    }

    protected function markVerificationError(GatewayTransaction $refund, string $message): array
    {
        $raw = $refund->raw_response ?? [];
        data_set($raw, 'refund_verification', [
            'checked_at' => now()->toISOString(),
            'error'      => $message,
        ]);
        $refund->raw_response = $raw;
        $refund->save();

        return [
            'id'      => $refund->public_id ?? $refund->uuid,
            'status'  => 'error',
            'message' => $message,
        ];
    }

    protected function recalculateInvoiceRefundState(Invoice $invoice): void
    {
        $pendingAmount = GatewayTransaction::where('company_uuid', $invoice->company_uuid)
            ->whereHas('gateway', fn ($query) => $query->where('driver', 'taler'))
            ->where('type', 'refund')
            ->whereNull('refund_accepted_at')
            ->where(function ($query) use ($invoice) {
                $query->where('raw_response->invoice_uuid', $invoice->uuid)
                    ->orWhere('raw_response->data->invoice_uuid', $invoice->uuid)
                    ->orWhere('raw_response->metadata->invoice_uuid', $invoice->uuid);
            })
            ->sum('amount');

        $refundedAmount = (int) data_get($invoice->meta, 'refunded_amount', 0);
        $meta           = $invoice->meta ?? [];
        data_set($meta, 'pending_wallet_refund_amount', (int) $pendingAmount);
        $invoice->meta = $meta;

        if ((int) $pendingAmount <= 0) {
            $invoice->status = $refundedAmount >= (int) $invoice->total_amount ? 'refunded' : 'partial';
        } elseif (in_array($invoice->status, ['refund_pending', 'partial_refund_pending', 'refunded', 'partial'], true)) {
            $invoice->status = $refundedAmount >= (int) $invoice->total_amount ? 'refund_pending' : 'partial_refund_pending';
        }

        $invoice->save();
    }

    protected function isRefundAccepted(array $data, int $amount, ?string $currency): bool
    {
        if (filter_var(data_get($data, 'refund_pending'), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        [$takenCurrency, $takenAmount] = $this->parseTalerAmount(data_get($data, 'refund_taken'));

        if ($takenAmount <= 0) {
            return false;
        }

        if ($currency && $takenCurrency && strtoupper($currency) !== $takenCurrency) {
            return false;
        }

        return $takenAmount >= $amount;
    }

    protected function resolveInvoice(GatewayTransaction $refund): ?Invoice
    {
        $invoiceUuid = data_get($refund->raw_response, 'invoice_uuid')
            ?: data_get($refund->raw_response, 'data.invoice_uuid')
            ?: data_get($refund->raw_response, 'metadata.invoice_uuid');

        return $invoiceUuid ? Invoice::where('uuid', $invoiceUuid)->orWhere('public_id', $invoiceUuid)->first() : null;
    }

    protected function resolveOrderId(GatewayTransaction $refund): ?string
    {
        return data_get($refund->raw_response, 'original_gateway_reference_id')
            ?: data_get($refund->raw_response, 'data.order_id')
            ?: data_get($refund->raw_response, 'order_id')
            ?: $refund->gateway_reference_id;
    }

    protected function cumulativeRefundAmountForOrder(GatewayTransaction $refund, string $orderId): int
    {
        return (int) GatewayTransaction::where('company_uuid', $refund->company_uuid)
            ->where('gateway_uuid', $refund->gateway_uuid)
            ->where('type', 'refund')
            ->where('status', 'succeeded')
            ->where(function ($query) use ($orderId) {
                $query->where('gateway_reference_id', $orderId)
                    ->orWhere('raw_response->original_gateway_reference_id', $orderId)
                    ->orWhere('raw_response->order_id', $orderId)
                    ->orWhere('raw_response->data->order_id', $orderId);
            })
            ->where(function ($query) use ($refund) {
                $query->where('created_at', '<', $refund->created_at)
                    ->orWhere(function ($q) use ($refund) {
                        $q->where('created_at', $refund->created_at)
                            ->where('uuid', '<=', $refund->uuid);
                    });
            })
            ->sum('amount');
    }

    protected function parseTalerAmount(?string $amount): array
    {
        if (!$amount || !preg_match('/^([A-Z]{2,8}):(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            return [null, 0];
        }

        $fraction = str_pad($matches[3] ?? '00', 2, '0');

        return [$matches[1], ((int) $matches[2] * 100) + (int) $fraction];
    }
}
