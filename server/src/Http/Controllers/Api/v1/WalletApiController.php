<?php

namespace Fleetbase\Ledger\Http\Controllers\Api\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Ledger\Http\Resources\v1\Wallet as WalletResource;
use Fleetbase\Ledger\Http\Resources\v1\WalletTransaction as WalletTransactionResource;
use Fleetbase\Ledger\Models\Wallet;
use Fleetbase\Ledger\Models\WalletTransaction;
use Fleetbase\Ledger\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * WalletApiController.
 *
 * Public-facing API endpoints for wallet operations.
 * These routes are accessible to authenticated API consumers (customers, drivers)
 * via their API key, scoped to their own wallet only.
 *
 * All monetary amounts are in the smallest currency unit (cents).
 */
class WalletApiController extends Controller
{
    protected WalletService $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    /**
     * Get the authenticated subject's wallet.
     *
     * Automatically provisions a wallet if one does not yet exist.
     *
     * GET /api/v1/ledger/wallet
     */
    public function getWallet(Request $request): WalletResource
    {
        $subject = $this->resolveSubject($request);
        $wallet  = $this->walletService->getOrCreateWallet($subject, $request->input('currency', 'USD'));

        return new WalletResource($wallet);
    }

    /**
     * Get the wallet balance for the authenticated subject.
     *
     * GET /api/v1/ledger/wallet/balance
     *
     * Returns:
     *   - balance          (int)    Balance in smallest currency unit (cents)
     *   - formatted_balance (string) Human-readable balance (e.g., "10.50")
     *   - currency         (string) ISO 4217 currency code
     *   - status           (string) Wallet status
     */
    public function getBalance(Request $request): JsonResponse
    {
        $subject = $this->resolveSubject($request);
        $wallet  = $this->walletService->getOrCreateWallet($subject, $request->input('currency', 'USD'));

        return response()->json([
            'balance'           => $wallet->balance,
            'formatted_balance' => $wallet->formatted_balance,
            'currency'          => $wallet->currency,
            'status'            => $wallet->status,
        ]);
    }

    /**
     * Get the transaction history for the authenticated subject's wallet.
     *
     * GET /api/v1/ledger/wallet/transactions
     *
     * Supports filtering by: type, direction, status, date_from, date_to
     * Supports pagination via limit/page.
     */
    public function getTransactions(Request $request): AnonymousResourceCollection
    {
        $subject = $this->resolveSubject($request);
        $wallet  = $this->walletService->getOrCreateWallet($subject);

        $transactions = WalletTransaction::where('wallet_uuid', $wallet->uuid)
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('direction'), fn ($q) => $q->where('direction', $request->input('direction')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->input('date_to')))
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('limit', 25));

        return WalletTransactionResource::collection($transactions);
    }

    /**
     * Top up the authenticated subject's wallet via a payment gateway.
     *
     * POST /api/v1/ledger/wallet/topup
     *
     * Request body:
     *   - gateway              (string, required)  Gateway public_id or UUID
     *   - amount               (int, required)     Amount in cents
     *   - payment_method_token (string)            Stripe pm_xxx token
     *   - customer_id          (string)            Gateway customer ID
     *   - description          (string)
     */
    public function topUp(Request $request): JsonResponse
    {
        $request->validate([
            'gateway'              => 'required|string',
            'amount'               => 'required|integer|min:100',
            'payment_method_token' => 'nullable|string',
            'customer_id'          => 'nullable|string',
            'description'          => 'nullable|string|max:500',
        ]);

        $subject = $this->resolveSubject($request);
        $wallet  = $this->walletService->getOrCreateWallet($subject);

        try {
            $result = $this->walletService->topUp(
                wallet: $wallet,
                amount: $request->integer('amount'),
                gatewayUuid: $request->input('gateway'),
                paymentData: $request->only(['payment_method_token', 'customer_id']),
                description: $request->input('description', 'Wallet top-up')
            );

            $response = [
                'wallet'           => new WalletResource($result['wallet']),
                'gateway_response' => [
                    'status'               => $result['gateway_response']->status,
                    'event_type'           => $result['gateway_response']->eventType,
                    'gateway_reference_id' => $result['gateway_response']->gatewayTransactionId,
                    'data'                 => $result['gateway_response']->data,
                ],
            ];

            if ($result['transaction']) {
                $response['transaction'] = new WalletTransactionResource($result['transaction']);
            }

            return response()->json($response);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    /**
     * Resolve the authenticated subject from the request.
     *
     * In Fleetbase, API requests carry a consumer (driver or customer) identified
     * by their API key. This method resolves the underlying model.
     *
     * Falls back to the authenticated user if no consumer is present.
     *
     * @throws \Illuminate\Auth\AuthenticationException
     */
    protected function resolveSubject(Request $request): \Illuminate\Database\Eloquent\Model
    {
        // Prefer the API consumer (driver/customer) if available
        if ($request->has('_consumer') && $request->get('_consumer')) {
            return $request->get('_consumer');
        }

        // Fall back to the authenticated user
        $user = $request->user();
        if (!$user) {
            abort(401, 'Unauthenticated.');
        }

        return $user;
    }
}
