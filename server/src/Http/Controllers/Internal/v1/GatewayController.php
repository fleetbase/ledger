<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Ledger\DTO\PurchaseRequest;
use Fleetbase\Ledger\DTO\RefundRequest;
use Fleetbase\Ledger\Http\Controllers\LedgerResourceController;
use Fleetbase\Ledger\Http\Resources\v1\GatewayTransaction as GatewayTransactionResource;
use Fleetbase\Ledger\Models\Gateway;
use Fleetbase\Ledger\Models\GatewayTransaction;
use Fleetbase\Ledger\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GatewayController extends LedgerResourceController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'gateway';

    /**
     * The PaymentService instance.
     */
    protected PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        parent::__construct();
        $this->paymentService = $paymentService;
    }

    /**
     * Return all available payment driver manifests (name, config schema, capabilities).
     */
    public function drivers(): JsonResponse
    {
        return response()->json([
            'status'  => 'ok',
            'drivers' => $this->paymentService->getDriverManifest(),
        ]);
    }

    public function summary(): JsonResponse
    {
        $companyUuid = session('company');
        $gateways = Gateway::where('company_uuid', $companyUuid)->get();
        $gatewayUuids = $gateways->pluck('uuid');
        $lastPayment = $this->latestGatewayTransaction($gatewayUuids, fn ($query) => $query->whereIn('event_type', [
            \Fleetbase\Ledger\DTO\GatewayResponse::EVENT_PAYMENT_PENDING,
            \Fleetbase\Ledger\DTO\GatewayResponse::EVENT_PAYMENT_SUCCEEDED,
        ]));
        $lastRefund = $this->latestGatewayTransaction($gatewayUuids, fn ($query) => $query->where('type', 'refund'));
        $lastSettlement = $this->latestGatewayTransaction($gatewayUuids, fn ($query) => $query->whereNotNull('reconciliation_checked_at'), 'reconciliation_checked_at');
        $driverSummary = collect($this->paymentService->getDriverManifest())->map(function ($driver) use ($gateways) {
            $driverGateways = $gateways->where('driver', $driver['code']);

            return [
                'code'         => $driver['code'],
                'name'         => $driver['name'],
                'capabilities' => $driver['capabilities'] ?? [],
                'configured'   => $driverGateways->count(),
                'active'       => $driverGateways->where('status', 'active')->count(),
                'live'         => $driverGateways->where('environment', 'live')->count(),
                'sandbox'      => $driverGateways->where('environment', 'sandbox')->count(),
            ];
        })->values();

        return response()->json([
            'status' => 'ok',
            'summary' => [
                'total_gateways'     => $gateways->count(),
                'active_gateways'    => $gateways->where('status', 'active')->count(),
                'live_gateways'      => $gateways->where('environment', 'live')->count(),
                'sandbox_gateways'   => $gateways->where('environment', 'sandbox')->count(),
                'webhook_warnings'   => $gateways->filter(fn ($gateway) => $gateway->status === 'active' && $gateway->driver !== 'cash' && empty($gateway->webhook_url))->count(),
                'last_payment_at'    => optional($lastPayment?->created_at)->toISOString(),
                'last_refund_at'     => optional($lastRefund?->created_at)->toISOString(),
                'last_settlement_at' => optional($lastSettlement?->reconciliation_checked_at)->toISOString(),
            ],
            'drivers' => $driverSummary,
        ]);
    }

    /**
     * Initiate a payment charge through a gateway.
     */
    public function charge(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'amount'      => 'required|integer|min:1',
            'currency'    => 'required|string|size:3',
            'description' => 'required|string|max:500',
        ]);

        $purchaseRequest = new PurchaseRequest(
            amount: $request->integer('amount'),
            currency: strtoupper($request->input('currency')),
            description: $request->input('description'),
            paymentMethodToken: $request->input('payment_method_token'),
            customerId: $request->input('customer_id'),
            customerEmail: $request->input('customer_email'),
            invoiceUuid: $request->input('invoice_uuid'),
            orderUuid: $request->input('order_uuid'),
            returnUrl: $request->input('return_url'),
            cancelUrl: $request->input('cancel_url'),
            metadata: $request->input('metadata', []),
        );

        $response = $this->paymentService->charge($id, $purchaseRequest);

        return response()->json([
            'status'                 => $response->status,
            'successful'             => $response->successful,
            'gateway_transaction_id' => $response->gatewayTransactionId,
            'message'                => $response->message,
            'data'                   => $response->data,
        ], $response->isSuccessful() ? 200 : 422);
    }

    /**
     * Refund a previously captured transaction.
     */
    public function refund(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'gateway_transaction_id' => 'required|string',
            'amount'                 => 'required|integer|min:1',
            'currency'               => 'required|string|size:3',
        ]);

        $refundRequest = new RefundRequest(
            gatewayTransactionId: $request->input('gateway_transaction_id'),
            amount: $request->integer('amount'),
            currency: strtoupper($request->input('currency')),
            reason: $request->input('reason'),
            invoiceUuid: $request->input('invoice_uuid'),
            metadata: $request->input('metadata', []),
        );

        $response = $this->paymentService->refund($id, $refundRequest);

        return response()->json([
            'status'                 => $response->status,
            'successful'             => $response->successful,
            'gateway_transaction_id' => $response->gatewayTransactionId,
            'message'                => $response->message,
            'data'                   => $response->data,
        ], $response->isSuccessful() ? 200 : 422);
    }

    /**
     * Create a setup intent for payment method tokenization (e.g. Stripe SetupIntent).
     */
    public function setupIntent(Request $request, string $id): JsonResponse
    {
        $response = $this->paymentService->createPaymentMethod($id, $request->all());

        return response()->json([
            'status'     => $response->status,
            'successful' => $response->successful,
            'message'    => $response->message,
            'data'       => $response->data,
        ], $response->isSuccessful() ? 200 : 422);
    }

    /**
     * List gateway transactions for a specific gateway.
     *
     * Returns a paginated list of GatewayTransaction records for the given gateway.
     * The tab in the UI is labelled "Transactions" (previously mislabelled "Webhooks").
     */
    public function transactions(Request $request, string $id): JsonResponse
    {
        $gateway = Gateway::where('company_uuid', session('company'))
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))
            ->firstOrFail();

        $transactions = GatewayTransaction::where('gateway_uuid', $gateway->uuid)
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 25));

        return response()->json(GatewayTransactionResource::collection($transactions));
    }

    public function testCredentials(Request $request, string $id): JsonResponse
    {
        $gateway = $this->resolveGateway($id);
        $driver  = $this->paymentServiceGatewayDriver($gateway);

        if (!method_exists($driver, 'testCredentials')) {
            return response()->json([
                'status'  => 'unsupported',
                'message' => "Gateway driver [{$gateway->driver}] does not support credential diagnostics.",
            ], 422);
        }

        return response()->json($driver->testCredentials());
    }

    public function createTestOrder(Request $request, string $id): JsonResponse
    {
        $gateway = $this->resolveGateway($id);
        $driver  = $this->paymentServiceGatewayDriver($gateway);

        if (!method_exists($driver, 'createTestOrder')) {
            return response()->json([
                'status'  => 'unsupported',
                'message' => "Gateway driver [{$gateway->driver}] does not support test orders.",
            ], 422);
        }

        $response = $driver->createTestOrder([
            'amount'      => $request->integer('amount', 1),
            'currency'    => strtoupper($request->input('currency', 'KUDOS')),
            'description' => $request->input('description', 'Ledger GNU Taler test order'),
            'metadata'    => [
                'company_uuid'      => $gateway->company_uuid,
                'gateway_uuid'      => $gateway->uuid,
                'gateway_public_id' => $gateway->public_id,
            ],
        ]);

        if ($response->gatewayTransactionId) {
            GatewayTransaction::create([
                'company_uuid'         => $gateway->company_uuid,
                'gateway_uuid'         => $gateway->uuid,
                'gateway_reference_id' => $response->gatewayTransactionId,
                'type'                 => 'test_order',
                'event_type'           => $response->eventType,
                'amount'               => $response->amount,
                'currency'             => $response->currency,
                'status'               => $response->status,
                'message'              => $response->message,
                'raw_response'         => array_merge($response->rawResponse, ['data' => $response->data]),
            ]);
        }

        return response()->json([
            'status'                 => $response->status,
            'successful'             => $response->successful,
            'gateway_transaction_id' => $response->gatewayTransactionId,
            'message'                => $response->message,
            'data'                   => $response->data,
        ], $response->isSuccessful() ? 200 : 422);
    }

    public function registerWebhook(Request $request, string $id): JsonResponse
    {
        $gateway = $this->resolveGateway($id);
        $driver  = $this->paymentServiceGatewayDriver($gateway);

        if (!method_exists($driver, 'registerWebhook')) {
            return response()->json([
                'status'  => 'unsupported',
                'message' => "Gateway driver [{$gateway->driver}] does not support webhook provisioning.",
            ], 422);
        }

        $result = $driver->registerWebhook([
            'webhook_url'  => $request->input('webhook_url') ?: $gateway->getWebhookUrl(),
            'company_uuid' => $gateway->company_uuid,
            'gateway_id'   => $gateway->public_id ?? $gateway->uuid,
            'gateway_uuid' => $gateway->uuid,
        ]);

        if (($result['ok'] ?? false) && $gateway->webhook_url !== ($result['payload']['url'] ?? null)) {
            $gateway->webhook_url = $result['payload']['url'] ?? $gateway->webhook_url;
            $gateway->save();
        }

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function diagnostics(Request $request, string $id): JsonResponse
    {
        $gateway = $this->resolveGateway($id);

        $lastWebhook = GatewayTransaction::where('gateway_uuid', $gateway->uuid)
            ->where('type', 'webhook_event')
            ->orderBy('created_at', 'desc')
            ->first();
        $lastPayment = GatewayTransaction::where('gateway_uuid', $gateway->uuid)
            ->whereIn('event_type', [\Fleetbase\Ledger\DTO\GatewayResponse::EVENT_PAYMENT_PENDING, \Fleetbase\Ledger\DTO\GatewayResponse::EVENT_PAYMENT_SUCCEEDED])
            ->orderBy('created_at', 'desc')
            ->first();
        $lastRefund = GatewayTransaction::where('gateway_uuid', $gateway->uuid)
            ->where('type', 'refund')
            ->orderBy('created_at', 'desc')
            ->first();
        $lastSettlement = GatewayTransaction::where('gateway_uuid', $gateway->uuid)
            ->whereNotNull('reconciliation_checked_at')
            ->orderBy('reconciliation_checked_at', 'desc')
            ->first();

        return response()->json([
            'status' => 'ok',
            'gateway' => [
                'id'                 => $gateway->public_id,
                'uuid'               => $gateway->uuid,
                'driver'             => $gateway->driver,
                'webhook_url'        => $gateway->webhook_url,
                'system_webhook_url' => $gateway->getWebhookUrl(),
            ],
            'diagnostics' => [
                'credential_status'        => 'not_checked',
                'webhook_registration'     => $gateway->webhook_url ? 'configured' : 'not_configured',
                'last_webhook_received_at' => optional($lastWebhook?->created_at)->toISOString(),
                'last_payment_event_at'    => optional($lastPayment?->created_at)->toISOString(),
                'last_refund_event_at'     => optional($lastRefund?->created_at)->toISOString(),
                'last_settlement_seen_at'  => optional($lastSettlement?->reconciliation_checked_at)->toISOString(),
                'last_reconciliation_status' => $lastSettlement?->reconciliation_status,
            ],
            'last_webhook'    => $lastWebhook ? (new GatewayTransactionResource($lastWebhook))->resolve() : null,
            'last_payment'    => $lastPayment ? (new GatewayTransactionResource($lastPayment))->resolve() : null,
            'last_refund'     => $lastRefund ? (new GatewayTransactionResource($lastRefund))->resolve() : null,
            'last_settlement' => $lastSettlement ? (new GatewayTransactionResource($lastSettlement))->resolve() : null,
        ]);
    }

    private function resolveGateway(string $id): Gateway
    {
        return Gateway::where('company_uuid', session('company'))
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))
            ->firstOrFail();
    }

    private function paymentServiceGatewayDriver(Gateway $gateway)
    {
        return app(\Fleetbase\Ledger\PaymentGatewayManager::class)
            ->driver($gateway->driver)
            ->initialize($gateway->decryptedConfig(), $gateway->is_sandbox);
    }

    private function latestGatewayTransaction($gatewayUuids, callable $scope, string $orderBy = 'created_at'): ?GatewayTransaction
    {
        if ($gatewayUuids->isEmpty()) {
            return null;
        }

        $query = GatewayTransaction::whereIn('gateway_uuid', $gatewayUuids);
        $scope($query);

        return $query->orderBy($orderBy, 'desc')->first();
    }
}
