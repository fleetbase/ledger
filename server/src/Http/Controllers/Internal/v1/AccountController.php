<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Ledger\Http\Controllers\LedgerController;
use Fleetbase\Ledger\Http\Resources\v1\Account as AccountResource;
use Fleetbase\Ledger\Models\Account;
use Fleetbase\Ledger\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends LedgerController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'account';

    /**
     * Recalculate and update the balance for an account.
     */
    public function recalculateBalance(string $id, Request $request): AccountResource
    {
        $account = Account::where('company_uuid', session('company'))
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))
            ->firstOrFail();

        $account->updateBalance();

        return new AccountResource($account);
    }

    /**
     * Return the general ledger for a specific account.
     */
    public function generalLedger(string $id, Request $request): JsonResponse
    {
        $account = Account::where('company_uuid', session('company'))
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))
            ->firstOrFail();

        $entries = app(LedgerService::class)->getGeneralLedger(
            $account,
            $request->input('date_from'),
            $request->input('date_to')
        );

        return response()->json([
            'account' => new AccountResource($account),
            'entries' => $entries,
        ]);
    }
}
