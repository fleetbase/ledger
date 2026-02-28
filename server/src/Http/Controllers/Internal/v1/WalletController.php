<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Ledger\Http\Resources\v1\Wallet as WalletResource;
use Fleetbase\Ledger\Models\Wallet;
use Fleetbase\Ledger\Services\WalletService;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'wallet';

    /**
     * The model to query.
     *
     * @var \Fleetbase\Ledger\Models\Wallet
     */
    public $model = Wallet::class;

    /**
     * Query for wallets.
     *
     * @return \Illuminate\Http\Response
     */
    public function query(Request $request)
    {
        $results = Wallet::where('company_uuid', session('company'))
            ->with(['subject'])
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->input('status'));
            })
            ->when($request->filled('subject_type'), function ($query) use ($request) {
                $query->where('subject_type', $request->input('subject_type'));
            })
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->input('search');
                $query->where('public_id', 'like', "%{$search}%");
            })
            ->orderBy($request->input('sort', 'created_at'), $request->input('order', 'desc'))
            ->paginate($request->input('limit', 15));

        return WalletResource::collection($results);
    }

    /**
     * Find a single wallet.
     *
     * @return \Illuminate\Http\Response
     */
    public function find($id, Request $request)
    {
        $wallet = Wallet::where('company_uuid', session('company'))
            ->with(['subject'])
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->firstOrFail();

        return new WalletResource($wallet);
    }

    /**
     * Create a new wallet.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request)
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
            'currency'     => $request->input('currency', 'USD'),
            'balance'      => 0,
            'status'       => 'active',
        ]);

        return new WalletResource($wallet->load('subject'));
    }

    /**
     * Update a wallet.
     *
     * @return \Illuminate\Http\Response
     */
    public function update($id, Request $request)
    {
        $wallet = Wallet::where('company_uuid', session('company'))
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->firstOrFail();

        $request->validate([
            'status' => 'sometimes|in:active,frozen,closed',
        ]);

        $wallet->update($request->only(['status']));

        return new WalletResource($wallet);
    }

    /**
     * Delete a wallet.
     *
     * @return \Illuminate\Http\Response
     */
    public function delete($id, Request $request)
    {
        $wallet = Wallet::where('company_uuid', session('company'))
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->firstOrFail();

        if ($wallet->balance != 0) {
            return response()->json(['error' => 'Cannot delete wallet with non-zero balance'], 400);
        }

        $wallet->delete();

        return response()->json(['status' => 'ok']);
    }

    /**
     * Deposit funds into a wallet.
     *
     * @return \Illuminate\Http\Response
     */
    public function deposit($id, Request $request)
    {
        $request->validate([
            'amount'      => 'required|integer|min:1',
            'description' => 'nullable|string',
        ]);

        $wallet = Wallet::where('company_uuid', session('company'))
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->firstOrFail();

        $walletService = app(WalletService::class);
        $wallet        = $walletService->deposit(
            $wallet,
            $request->input('amount'),
            $request->input('description', '')
        );

        return new WalletResource($wallet);
    }

    /**
     * Withdraw funds from a wallet.
     *
     * @return \Illuminate\Http\Response
     */
    public function withdraw($id, Request $request)
    {
        $request->validate([
            'amount'      => 'required|integer|min:1',
            'description' => 'nullable|string',
        ]);

        $wallet = Wallet::where('company_uuid', session('company'))
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->firstOrFail();

        $walletService = app(WalletService::class);
        $wallet        = $walletService->withdraw(
            $wallet,
            $request->input('amount'),
            $request->input('description', '')
        );

        return new WalletResource($wallet);
    }

    /**
     * Transfer funds between wallets.
     *
     * @return \Illuminate\Http\Response
     */
    public function transfer(Request $request)
    {
        $request->validate([
            'from_wallet' => 'required|string',
            'to_wallet'   => 'required|string',
            'amount'      => 'required|integer|min:1',
            'description' => 'nullable|string',
        ]);

        $fromWallet = Wallet::where('company_uuid', session('company'))
            ->where(function ($query) use ($request) {
                $id = $request->input('from_wallet');
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->firstOrFail();

        $toWallet = Wallet::where('company_uuid', session('company'))
            ->where(function ($query) use ($request) {
                $id = $request->input('to_wallet');
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->firstOrFail();

        $walletService = app(WalletService::class);
        $result        = $walletService->transfer(
            $fromWallet,
            $toWallet,
            $request->input('amount'),
            $request->input('description', '')
        );

        return response()->json([
            'from_wallet' => new WalletResource($result['from_wallet']),
            'to_wallet'   => new WalletResource($result['to_wallet']),
        ]);
    }
}
