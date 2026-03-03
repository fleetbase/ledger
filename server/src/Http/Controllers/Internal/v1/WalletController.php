<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Http\Resources\FleetbaseResourceCollection;
use Fleetbase\Ledger\Http\Controllers\LedgerResourceController;
use Fleetbase\Ledger\Http\Resources\v1\Transaction as TransactionResource;
use Fleetbase\Ledger\Http\Resources\v1\Wallet as WalletResource;
use Fleetbase\Ledger\Models\Transaction;
use Fleetbase\Ledger\Models\Wallet;
use Fleetbase\Ledger\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WalletController extends LedgerResourceController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'wallet';

    /**
     * The WalletService instance.
     */
    protected WalletService $walletService;

    public function __construct(WalletService $walletService)
    {
        parent::__construct();
        $this->walletService = $walletService;
    }

    // =========================================================================
    // Financial Operations
    // =========================================================================

    /**
     * Transfer funds between two wallets.
     */
    public function transfer(string $id, Request $request): JsonResponse
    {
        $request->validate([
            'to_wallet_uuid' => 'required|uuid',
            'amount'         => 'required|integer|min:1',
            'description'    => 'nullable|string|max:500',
        ]);

        $fromWallet = $this->resolveWallet($id);
        $toWallet   = $this->resolveWallet($request->input('to_wallet_uuid'));

        $result = $this->walletService->transfer(
            $fromWallet,
            $toWallet,
            $request->integer('amount'),
            $request->input('description', 'Wallet transfer')
        );

        return response()->json([
            'from_wallet'    => new WalletResource($fromWallet->fresh()),
            'to_wallet'      => new WalletResource($toWallet->fresh()),
            'transaction'    => new TransactionResource($result['from']),
            'to_transaction' => new TransactionResource($result['to']),
        ]);
    }

    /**
     * Manually credit a wallet (admin/internal deposit, no gateway required).
     */
    public function credit(string $id, Request $request): JsonResponse
    {
        $request->validate([
            'amount'      => 'required|integer|min:1',
            'description' => 'nullable|string|max:500',
        ]);

        $wallet = $this->resolveWallet($id);

        $transaction = $this->walletService->deposit(
            $wallet,
            $request->integer('amount'),
            $request->input('description', 'Manual credit'),
            'deposit'
        );

        return response()->json([
            'wallet'      => new WalletResource($wallet->fresh()),
            'transaction' => new TransactionResource($transaction),
        ]);
    }

    /**
     * Top up a wallet via a payment gateway.
     */
    public function topUp(string $id, Request $request): JsonResponse
    {
        $request->validate([
            'amount'               => 'required|integer|min:1',
            'gateway_uuid'         => 'required|uuid',
            'payment_method_token' => 'required|string',
            'description'          => 'nullable|string|max:500',
        ]);

        $wallet = $this->resolveWallet($id);

        $result = $this->walletService->topUp(
            $wallet,
            $request->integer('amount'),
            $request->input('gateway_uuid'),
            [
                'payment_method_token' => $request->input('payment_method_token'),
                'customer_email'       => $request->input('customer_email'),
                'customer_id'          => $request->input('customer_id'),
                'metadata'             => $request->input('metadata', []),
            ],
            $request->input('description', 'Wallet top-up')
        );

        $gatewayResponse = $result['gateway_response'];
        $response = [
            'wallet'           => new WalletResource($wallet->fresh()),
            'status'           => $gatewayResponse->status ?? 'unknown',
            'gateway_response' => $gatewayResponse,
        ];

        if ($result['transaction']) {
            $response['transaction'] = new TransactionResource($result['transaction']);
        }

        return response()->json($response);
    }

    /**
     * Process a payout from a wallet (e.g. driver earnings withdrawal).
     */
    public function payout(string $id, Request $request): JsonResponse
    {
        $request->validate([
            'amount'      => 'required|integer|min:1',
            'description' => 'nullable|string|max:500',
            'reference'   => 'nullable|string|max:191',
        ]);

        $wallet = $this->resolveWallet($id);

        $transaction = $this->walletService->withdraw(
            $wallet,
            $request->integer('amount'),
            $request->input('description', 'Driver payout'),
            'payout',
            ['reference' => $request->input('reference')]
        );

        return response()->json([
            'wallet'      => new WalletResource($wallet->fresh()),
            'transaction' => new TransactionResource($transaction),
        ]);
    }

    // =========================================================================
    // Transaction History
    // =========================================================================

    /**
     * Get the transaction history for a specific wallet.
     * Queries the core Transaction model filtered by owner (wallet).
     */
    public function getTransactions(string $id, Request $request): AnonymousResourceCollection|FleetbaseResourceCollection
    {
        $wallet = $this->resolveWallet($id);

        $transactions = Transaction::where('owner_uuid', $wallet->uuid)
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('direction'), fn ($q) => $q->where('direction', $request->input('direction')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->input('date_to')))
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('limit', 25));

        TransactionResource::wrap('transactions');

        return TransactionResource::collection($transactions);
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
     */
    public function recalculate(string $id, Request $request): JsonResponse
    {
        $wallet     = $this->resolveWallet($id);
        $oldBalance = $wallet->balance;
        $newBalance = $this->walletService->recalculateBalance($wallet);

        return response()->json([
            'wallet'      => new WalletResource($wallet->fresh()),
            'old_balance' => $oldBalance,
            'new_balance' => $newBalance,
            'corrected'   => $oldBalance !== $newBalance,
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    protected function resolveWallet(string $id): Wallet
    {
        return Wallet::where('company_uuid', session('company'))
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))
            ->firstOrFail();
    }
}
