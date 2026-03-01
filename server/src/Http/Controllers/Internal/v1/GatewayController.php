<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Ledger\DTO\PurchaseRequest;
use Fleetbase\Ledger\DTO\RefundRequest;
use Fleetbase\Ledger\Http\Controllers\LedgerController;
use Fleetbase\Ledger\Http\Resources\v1\GatewayTransaction as GatewayTransactionResource;
use Fleetbase\Ledger\Models\Gateway;
use Fleetbase\Ledger\Models\GatewayTransaction;
use Fleetbase\Ledger\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GatewayController extends LedgerController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'gateway';

    public function __construct(protected PaymentService $paymentService)
    {
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
     */
    public function transactions(Request $request, string $id): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $gateway = Gateway::where('company_uuid', session('company'))
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))
            ->firstOrFail();

        $transactions = GatewayTransaction::where('gateway_uuid', $gateway->uuid)
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 25));

        return GatewayTransactionResource::collection($transactions);
    }
}
