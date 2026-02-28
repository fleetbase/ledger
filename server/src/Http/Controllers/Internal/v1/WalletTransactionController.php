<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Ledger\Http\Resources\v1\WalletTransaction as WalletTransactionResource;
use Fleetbase\Ledger\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * WalletTransactionController
 *
 * Provides a standalone query endpoint for wallet transactions across all wallets.
 * Useful for the Ledger dashboard to show a company-wide transaction feed.
 *
 * @package Fleetbase\Ledger\Http\Controllers\Internal\v1
 */
class WalletTransactionController extends Controller
{
    /**
     * Query wallet transactions across all wallets for the authenticated company.
     *
     * Supports filtering by: wallet_uuid, type, direction, status, date_from, date_to, search
     */
    public function query(Request $request): AnonymousResourceCollection
    {
        $results = WalletTransaction::where('company_uuid', session('company'))
            ->with(['wallet', 'subject'])
            ->when($request->filled('wallet'), fn ($q) => $q->where('wallet_uuid', $request->input('wallet')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('direction'), fn ($q) => $q->where('direction', $request->input('direction')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->input('date_to')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->input('search');
                $q->where(function ($inner) use ($search) {
                    $inner->where('public_id', 'like', "%{$search}%")
                          ->orWhere('description', 'like', "%{$search}%")
                          ->orWhere('reference', 'like', "%{$search}%");
                });
            })
            ->orderBy($request->input('sort', 'created_at'), $request->input('order', 'desc'))
            ->paginate($request->input('limit', 25));

        return WalletTransactionResource::collection($results);
    }

    /**
     * Find a single wallet transaction by UUID or public_id.
     */
    public function find(string $id, Request $request): WalletTransactionResource
    {
        $transaction = WalletTransaction::where('company_uuid', session('company'))
            ->with(['wallet', 'subject'])
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))
            ->firstOrFail();

        return new WalletTransactionResource($transaction);
    }
}
