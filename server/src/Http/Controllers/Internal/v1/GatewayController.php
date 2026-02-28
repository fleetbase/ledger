<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Ledger\DTO\PurchaseRequest;
use Fleetbase\Ledger\DTO\RefundRequest;
use Fleetbase\Ledger\Http\Resources\v1\Gateway as GatewayResource;
use Fleetbase\Ledger\Models\Gateway;
use Fleetbase\Ledger\Models\GatewayTransaction;
use Fleetbase\Ledger\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * GatewayController
 *
 * Handles all CRUD operations for payment gateways and exposes the
 * driver manifest endpoint used by the frontend to render dynamic
 * gateway configuration forms.
 *
 * Routes:
 *   GET    /ledger/int/v1/gateways            → index (list all gateways for company)
 *   POST   /ledger/int/v1/gateways            → store (create new gateway)
 *   GET    /ledger/int/v1/gateways/{id}       → show (get single gateway)
 *   PUT    /ledger/int/v1/gateways/{id}       → update (update gateway config)
 *   DELETE /ledger/int/v1/gateways/{id}       → destroy (delete gateway)
 *   GET    /ledger/int/v1/gateways/drivers    → drivers (available driver manifest)
 *   POST   /ledger/int/v1/gateways/{id}/charge → charge (initiate a payment)
 *   POST   /ledger/int/v1/gateways/{id}/refund → refund (refund a transaction)
 *   POST   /ledger/int/v1/gateways/{id}/setup-intent → setupIntent (tokenize card)
 *
 * @package Fleetbase\Ledger\Http\Controllers\Internal\v1
 */
class GatewayController extends FleetbaseController
{
    /**
     * The resource model class.
     */
    protected string $resource = 'gateway';

    /**
     * The model class.
     */
    protected string $model = Gateway::class;

    public function __construct(
        protected PaymentService $paymentService,
    ) {
    }

    /**
     * List all gateways for the authenticated company.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $companyUuid = session('company');

        $query = Gateway::where('company_uuid', $companyUuid);

        // Optional filters
        if ($request->has('driver')) {
            $query->where('driver', $request->input('driver'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $gateways = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'status'  => 'ok',
            'gateways' => GatewayResource::collection($gateways),
        ]);
    }

    /**
     * Create a new payment gateway configuration.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name'       => 'required|string|max:191',
            'driver'     => ['required', 'string', Rule::in(['stripe', 'qpay', 'cash'])],
            'config'     => 'required|array',
            'is_sandbox' => 'boolean',
            'status'     => ['string', Rule::in(['active', 'inactive'])],
            'return_url' => 'nullable|url',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error'   => 'Validation failed.',
                'details' => $validator->errors(),
            ], 422);
        }

        $companyUuid = session('company');
        $userUuid    = session('user');

        // Resolve capabilities from the driver manifest
        $manifest     = collect($this->paymentService->getDriverManifest());
        $driverInfo   = $manifest->firstWhere('code', $request->input('driver'));
        $capabilities = $driverInfo['capabilities'] ?? [];

        $gateway = Gateway::create([
            'company_uuid'    => $companyUuid,
            'created_by_uuid' => $userUuid,
            'name'            => $request->input('name'),
            'driver'          => $request->input('driver'),
            'description'     => $request->input('description'),
            'config'          => $request->input('config'),   // Encrypted at rest by model cast
            'capabilities'    => $capabilities,
            'is_sandbox'      => $request->boolean('is_sandbox', false),
            'status'          => $request->input('status', 'active'),
            'return_url'      => $request->input('return_url'),
            'webhook_url'     => url('/ledger/webhooks/' . $request->input('driver')),
        ]);

        return response()->json([
            'status'  => 'ok',
            'gateway' => new GatewayResource($gateway),
        ], 201);
    }

    /**
     * Get a single gateway by UUID or public_id.
     *
     * @param string $id
     *
     * @return JsonResponse
     */
    public function show(string $id): JsonResponse
    {
        $gateway = Gateway::where('company_uuid', session('company'))
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))
            ->firstOrFail();

        return response()->json([
            'status'  => 'ok',
            'gateway' => new GatewayResource($gateway),
        ]);
    }

    /**
     * Update a gateway configuration.
     *
     * @param Request $request
     * @param string  $id
     *
     * @return JsonResponse
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $gateway = Gateway::where('company_uuid', session('company'))
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'name'       => 'string|max:191',
            'config'     => 'array',
            'is_sandbox' => 'boolean',
            'status'     => ['string', Rule::in(['active', 'inactive'])],
            'return_url' => 'nullable|url',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error'   => 'Validation failed.',
                'details' => $validator->errors(),
            ], 422);
        }

        $updateData = array_filter([
            'name'        => $request->input('name'),
            'description' => $request->input('description'),
            'is_sandbox'  => $request->has('is_sandbox') ? $request->boolean('is_sandbox') : null,
            'status'      => $request->input('status'),
            'return_url'  => $request->input('return_url'),
        ], fn ($v) => $v !== null);

        // Only update config if explicitly provided (avoid overwriting credentials with null)
        if ($request->has('config') && is_array($request->input('config'))) {
            $updateData['config'] = array_merge(
                $gateway->decryptedConfig(),
                $request->input('config')
            );
        }

        $gateway->update($updateData);

        return response()->json([
            'status'  => 'ok',
            'gateway' => new GatewayResource($gateway->fresh()),
        ]);
    }

    /**
     * Delete a gateway configuration.
     *
     * @param string $id
     *
     * @return JsonResponse
     */
    public function destroy(string $id): JsonResponse
    {
        $gateway = Gateway::where('company_uuid', session('company'))
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))
            ->firstOrFail();

        $gateway->delete();

        return response()->json(['status' => 'ok', 'message' => 'Gateway deleted.']);
    }

    /**
     * Return the full driver manifest.
     *
     * This endpoint is used by the frontend to dynamically render the
     * "Add Gateway" configuration form. It returns each registered driver's
     * code, name, capabilities, and config schema.
     *
     * @return JsonResponse
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
     *
     * @param Request $request
     * @param string  $id      Gateway UUID or public_id
     *
     * @return JsonResponse
     */
    public function charge(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount'      => 'required|integer|min:1',
            'currency'    => 'required|string|size:3',
            'description' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error'   => 'Validation failed.',
                'details' => $validator->errors(),
            ], 422);
        }

        $purchaseRequest = new PurchaseRequest(
            amount:              $request->integer('amount'),
            currency:            strtoupper($request->input('currency')),
            description:         $request->input('description'),
            paymentMethodToken:  $request->input('payment_method_token'),
            customerId:          $request->input('customer_id'),
            customerEmail:       $request->input('customer_email'),
            invoiceUuid:         $request->input('invoice_uuid'),
            orderUuid:           $request->input('order_uuid'),
            returnUrl:           $request->input('return_url'),
            cancelUrl:           $request->input('cancel_url'),
            metadata:            $request->input('metadata', []),
        );

        $response = $this->paymentService->charge($id, $purchaseRequest);

        return response()->json([
            'status'                  => $response->status,
            'successful'              => $response->successful,
            'gateway_transaction_id'  => $response->gatewayTransactionId,
            'message'                 => $response->message,
            'data'                    => $response->data,
        ], $response->isSuccessful() ? 200 : 422);
    }

    /**
     * Refund a previously captured transaction.
     *
     * @param Request $request
     * @param string  $id      Gateway UUID or public_id
     *
     * @return JsonResponse
     */
    public function refund(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'gateway_transaction_id' => 'required|string',
            'amount'                 => 'required|integer|min:1',
            'currency'               => 'required|string|size:3',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error'   => 'Validation failed.',
                'details' => $validator->errors(),
            ], 422);
        }

        $refundRequest = new RefundRequest(
            gatewayTransactionId: $request->input('gateway_transaction_id'),
            amount:               $request->integer('amount'),
            currency:             strtoupper($request->input('currency')),
            reason:               $request->input('reason'),
            invoiceUuid:          $request->input('invoice_uuid'),
            metadata:             $request->input('metadata', []),
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
     * Create a setup intent for payment method tokenization (e.g., Stripe SetupIntent).
     *
     * @param Request $request
     * @param string  $id      Gateway UUID or public_id
     *
     * @return JsonResponse
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
     * @param Request $request
     * @param string  $id      Gateway UUID or public_id
     *
     * @return JsonResponse
     */
    public function transactions(Request $request, string $id): JsonResponse
    {
        $gateway = Gateway::where('company_uuid', session('company'))
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))
            ->firstOrFail();

        $query = GatewayTransaction::where('gateway_uuid', $gateway->uuid);

        if ($request->has('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $transactions = $query->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 25));

        return response()->json($transactions);
    }
}
