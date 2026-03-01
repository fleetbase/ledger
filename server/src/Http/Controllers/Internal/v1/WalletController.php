<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

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
 * WalletController.
 *
 * Handles all internal (authenticated) wallet operations for the Ledger extension.
 *
 * All monetary amounts in requests and responses are in the smallest currency
 * unit (cents) unless otherwise noted.
 */
class WalletController extends Controller
{
    /**
     * The resource to query.
     */
    public $resource = 'wallet';

    /**
     * The model to query.
     */
    public $model = Wallet::class;

    /**
     * The WalletService instance.
     */
    protected WalletService $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    // =========================================================================
    // CRUD
    // =========================================================================

    /**
     * Query wallets for the authenticated company.
     *
     * Supports filtering by: status, subject_type, search (public_id)
     * Supports sorting by any column.
     */
    public function query(Request $request): AnonymousResourceCollection
    {
        $results = Wallet::where('company_uuid', session('company'))
            ->with(['subject'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('subject_type'), fn ($q) => $q->where('subject_type', $request->input('subject_type')))
            ->when($request->filled('type'), function ($q) use ($request) {
                // Filter by inferred type (driver/customer/company) via subject_type LIKE
                $type = $request->input('type');
                $q->where('subject_type', 'like', "%{$type}%");
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->input('search');
                $q->where('public_id', 'like', "%{$search}%");
            })
            ->orderBy($request->input('sort', 'created_at'), $request->input('order', 'desc'))
            ->paginate($request->input('limit', 15));

        return WalletResource::collection($results);
    }

    /**
     * Find a single wallet by UUID or public_id.
     */
    public function find(string $id, Request $request): WalletResource
    {
        $wallet = Wallet::where('company_uuid', session('company'))
            ->with(['subject'])
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))
            ->firstOrFail();

        return new WalletResource($wallet);
    }

    /**
     * Create a new wallet for a subject.
     */
    public function create(Request $request): WalletResource
    {
        $request->validate([
            'subject_uuid' => 'required|string',
            'subject_type' => 'required|string',
            'currency'     => 'nullable|string|size:3',
        ]);

        $wallet = Wallet::create([
            'company_uuid' => session('company'),
            'subject_uuid' => $request->input('subject_uuid'),
            'subject_type' => $request->input('subject_type'),
            'currency'     => strtoupper($request->input('currency', 'USD')),
            'balance'      => 0,
            'status'       => Wallet::STATUS_ACTIVE,
        ]);

        return new WalletResource($wallet->load('subject'));
    }

    /**
     * Update wallet metadata (status only — balance changes go through dedicated endpoints).
     */
    public function update(string $id, Request $request): WalletResource
    {
        $wallet = $this->resolveWallet($id);

        $request->validate([
            'status' => 'sometimes|in:active,frozen,closed',
        ]);

        $wallet->update($request->only(['status']));

        return new WalletResource($wallet->fresh('subject'));
    }

    /**
     * Delete a wallet. Only allowed if balance is zero.
     */
    public function delete(string $id, Request $request): JsonResponse
    {
        $wallet = $this->resolveWallet($id);

        if ($wallet->balance !== 0) {
            return response()->json([
                'error' => "Cannot delete wallet [{$wallet->public_id}] with non-zero balance ({$wallet->balance} {$wallet->currency}).",
            ], 422);
        }

        $wallet->delete();

        return response()->json(['status' => 'ok', 'message' => 'Wallet deleted successfully.']);
    }

    // =========================================================================
    // Balance Operations
    // =========================================================================

    /**
     * Manually deposit funds into a wallet (operator action).
     *
     * Request body:
     *   - amount      (int, required)  Amount in cents
     *   - description (string)
     *   - reference   (string)         Optional external reference
     */
    public function deposit(string $id, Request $request): JsonResponse
    {
        $request->validate([
            'amount'      => 'required|integer|min:1',
            'description' => 'nullable|string|max:500',
            'reference'   => 'nullable|string|max:191',
        ]);

        $wallet = $this->resolveWallet($id);

        try {
            $transaction = $this->walletService->deposit(
                wallet: $wallet,
                amount: $request->integer('amount'),
                description: $request->input('description', ''),
                type: WalletTransaction::TYPE_DEPOSIT,
                options: ['reference' => $request->input('reference')]
            );

            return response()->json([
                'wallet'      => new WalletResource($wallet->fresh()),
                'transaction' => new WalletTransactionResource($transaction),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Manually withdraw funds from a wallet (operator action).
     *
     * Request body:
     *   - amount      (int, required)  Amount in cents
     *   - description (string)
     *   - reference   (string)         Optional external reference
     */
    public function withdraw(string $id, Request $request): JsonResponse
    {
        $request->validate([
            'amount'      => 'required|integer|min:1',
            'description' => 'nullable|string|max:500',
            'reference'   => 'nullable|string|max:191',
        ]);

        $wallet = $this->resolveWallet($id);

        try {
            $transaction = $this->walletService->withdraw(
                wallet: $wallet,
                amount: $request->integer('amount'),
                description: $request->input('description', ''),
                type: WalletTransaction::TYPE_WITHDRAWAL,
                options: ['reference' => $request->input('reference')]
            );

            return response()->json([
                'wallet'      => new WalletResource($wallet->fresh()),
                'transaction' => new WalletTransactionResource($transaction),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Transfer funds between two wallets.
     *
     * Request body:
     *   - from_wallet  (string, required)  UUID or public_id of source wallet
     *   - to_wallet    (string, required)  UUID or public_id of destination wallet
     *   - amount       (int, required)     Amount in cents
     *   - description  (string)
     */
    public function transfer(Request $request): JsonResponse
    {
        $request->validate([
            'from_wallet' => 'required|string',
            'to_wallet'   => 'required|string',
            'amount'      => 'required|integer|min:1',
            'description' => 'nullable|string|max:500',
        ]);

        $fromWallet = $this->resolveWallet($request->input('from_wallet'));
        $toWallet   = $this->resolveWallet($request->input('to_wallet'));

        try {
            $result = $this->walletService->transfer(
                fromWallet: $fromWallet,
                toWallet: $toWallet,
                amount: $request->integer('amount'),
                description: $request->input('description', '')
            );

            return response()->json([
                'from_wallet'        => new WalletResource($fromWallet->fresh()),
                'to_wallet'          => new WalletResource($toWallet->fresh()),
                'from_transaction'   => new WalletTransactionResource($result['from']),
                'to_transaction'     => new WalletTransactionResource($result['to']),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Top up a wallet by charging a payment gateway.
     *
     * Request body:
     *   - gateway        (string, required)  Gateway UUID or public_id
     *   - amount         (int, required)     Amount in cents
     *   - description    (string)
     *   - payment_method_token (string)      Stripe payment method token (pm_xxx)
     *   - customer_id    (string)            Gateway customer ID
     *   - customer_email (string)
     */
    public function topUp(string $id, Request $request): JsonResponse
    {
        $request->validate([
            'gateway'              => 'required|string',
            'amount'               => 'required|integer|min:1',
            'description'          => 'nullable|string|max:500',
            'payment_method_token' => 'nullable|string',
            'customer_id'          => 'nullable|string',
            'customer_email'       => 'nullable|email',
        ]);

        $wallet = $this->resolveWallet($id);

        try {
            $result = $this->walletService->topUp(
                wallet: $wallet,
                amount: $request->integer('amount'),
                gatewayUuid: $request->input('gateway'),
                paymentData: $request->only(['payment_method_token', 'customer_id', 'customer_email']),
                description: $request->input('description', '')
            );

            $response = [
                'wallet'           => new WalletResource($result['wallet']),
                'gateway_response' => $result['gateway_response'],
            ];

            if ($result['transaction']) {
                $response['transaction'] = new WalletTransactionResource($result['transaction']);
            }

            return response()->json($response);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Process a payout from a wallet (driver earnings withdrawal).
     *
     * Request body:
     *   - amount      (int, required)  Amount in cents
     *   - description (string)
     *   - reference   (string)         Optional external reference (bank transfer ID, etc.)
     */
    public function payout(string $id, Request $request): JsonResponse
    {
        $request->validate([
            'amount'      => 'required|integer|min:1',
            'description' => 'nullable|string|max:500',
            'reference'   => 'nullable|string|max:191',
        ]);

        $wallet = $this->resolveWallet($id);

        try {
            $transaction = $this->walletService->withdraw(
                wallet: $wallet,
                amount: $request->integer('amount'),
                description: $request->input('description', 'Driver payout'),
                type: WalletTransaction::TYPE_PAYOUT,
                options: ['reference' => $request->input('reference')]
            );

            return response()->json([
                'wallet'      => new WalletResource($wallet->fresh()),
                'transaction' => new WalletTransactionResource($transaction),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    // =========================================================================
    // Transaction History
    // =========================================================================

    /**
     * Get the transaction history for a wallet.
     *
     * Supports filtering by: type, direction, status, date_from, date_to
     * Supports pagination via limit/page.
     */
    public function getTransactions(string $id, Request $request): AnonymousResourceCollection
    {
        $wallet = $this->resolveWallet($id);

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

    // =========================================================================
    // State Management
    // =========================================================================

    /**
     * Freeze a wallet. Debits are blocked; credits are still accepted.
     */
    public function freeze(string $id, Request $request): WalletResource
    {
        $wallet = $this->resolveWallet($id);
        $wallet->freeze();

        return new WalletResource($wallet->fresh());
    }

    /**
     * Unfreeze (activate) a wallet.
     */
    public function unfreeze(string $id, Request $request): WalletResource
    {
        $wallet = $this->resolveWallet($id);
        $wallet->activate();

        return new WalletResource($wallet->fresh());
    }

    /**
     * Recalculate and correct a wallet's balance from its transaction history.
     * This is a reconciliation utility for operators.
     */
    public function recalculate(string $id, Request $request): JsonResponse
    {
        $wallet = $this->resolveWallet($id);

        $oldBalance  = $wallet->balance;
        $newBalance  = $this->walletService->recalculateBalance($wallet);

        return response()->json([
            'wallet'      => new WalletResource($wallet->fresh()),
            'old_balance' => $oldBalance,
            'new_balance' => $newBalance,
            'corrected'   => $oldBalance !== $newBalance,
        ]);
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    /**
     * Resolve a wallet by UUID or public_id, scoped to the authenticated company.
     */
    protected function resolveWallet(string $id): Wallet
    {
        return Wallet::where('company_uuid', session('company'))
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))
            ->firstOrFail();
    }
}
