<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Ledger\Http\Resources\v1\Account as AccountResource;
use Fleetbase\Ledger\Models\Account;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'account';

    /**
     * The model to query.
     *
     * @var \Fleetbase\Ledger\Models\Account
     */
    public $model = Account::class;

    /**
     * Query for accounts.
     *
     * @return \Illuminate\Http\Response
     */
    public function query(Request $request)
    {
        $results = Account::where('company_uuid', session('company'))
            ->when($request->filled('type'), function ($query) use ($request) {
                $query->where('type', $request->input('type'));
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->input('status'));
            })
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->orderBy($request->input('sort', 'created_at'), $request->input('order', 'desc'))
            ->paginate($request->input('limit', 15));

        return AccountResource::collection($results);
    }

    /**
     * Find a single account.
     *
     * @return \Illuminate\Http\Response
     */
    public function find($id, Request $request)
    {
        $account = Account::where('company_uuid', session('company'))
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->firstOrFail();

        return new AccountResource($account);
    }

    /**
     * Create a new account.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request)
    {
        $request->validate([
            'name'        => 'required|string|max:191',
            'code'        => 'nullable|string|max:191',
            'type'        => 'required|in:asset,liability,equity,revenue,expense',
            'description' => 'nullable|string',
            'currency'    => 'nullable|string|size:3',
        ]);

        $account = Account::create([
            'company_uuid' => session('company'),
            'name'         => $request->input('name'),
            'code'         => $request->input('code'),
            'type'         => $request->input('type'),
            'description'  => $request->input('description'),
            'currency'     => $request->input('currency', 'USD'),
            'status'       => 'active',
        ]);

        return new AccountResource($account);
    }

    /**
     * Update an account.
     *
     * @return \Illuminate\Http\Response
     */
    public function update($id, Request $request)
    {
        $account = Account::where('company_uuid', session('company'))
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->firstOrFail();

        $request->validate([
            'name'        => 'sometimes|required|string|max:191',
            'code'        => 'sometimes|nullable|string|max:191',
            'type'        => 'sometimes|required|in:asset,liability,equity,revenue,expense',
            'description' => 'nullable|string',
            'status'      => 'sometimes|in:active,inactive',
        ]);

        $account->update($request->only([
            'name',
            'code',
            'type',
            'description',
            'status',
        ]));

        return new AccountResource($account);
    }

    /**
     * Delete an account.
     *
     * @return \Illuminate\Http\Response
     */
    public function delete($id, Request $request)
    {
        $account = Account::where('company_uuid', session('company'))
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->firstOrFail();

        if ($account->is_system_account) {
            return response()->json(['error' => 'Cannot delete system account'], 400);
        }

        $account->delete();

        return response()->json(['status' => 'ok']);
    }

    /**
     * Recalculate and update the balance for an account.
     *
     * @return \Illuminate\Http\Response
     */
    public function recalculateBalance($id, Request $request)
    {
        $account = Account::where('company_uuid', session('company'))
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->firstOrFail();

        $account->updateBalance();

        return new AccountResource($account);
    }
}
