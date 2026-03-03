<?php

namespace Fleetbase\Ledger\Observers;

use Fleetbase\Ledger\Services\WalletService;
use Fleetbase\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * UserObserver.
 *
 * Listens on the core User model and automatically provisions a personal
 * Ledger wallet for every user, scoped to their company.
 *
 * A wallet is created on the `created` event when company_uuid is already
 * present. The `updated` hook handles the common case where company_uuid is
 * set in a subsequent save (e.g. after an invite is accepted or a user is
 * assigned to a company after initial registration).
 *
 * Wallets are scoped to the user's company (company_uuid) so that a user who
 * belongs to multiple companies receives a separate wallet per company.
 *
 * The provisioning call is independently try/caught so a failure does not
 * abort the user save.
 */
class UserObserver
{
    public function __construct(protected WalletService $walletService)
    {
    }

    /**
     * Handle the User "created" event.
     *
     * Provisions a personal wallet immediately if the user already has a company.
     */
    public function created(User $user): void
    {
        if (empty($user->company_uuid)) {
            return;
        }

        try {
            $this->walletService->provisionUserWallet($user);
        } catch (\Throwable $e) {
            Log::error('[Ledger] Failed to provision wallet for user ' . $user->uuid . ': ' . $e->getMessage());
        }
    }

    /**
     * Handle the User "updated" event.
     *
     * Handles the case where company_uuid is set after initial creation.
     * Only fires when company_uuid was just set for the first time (changed
     * from null to a value) to avoid redundant provisioning on every save.
     */
    public function updated(User $user): void
    {
        // Only act when company_uuid was just set for the first time
        if (!$user->wasChanged('company_uuid') || empty($user->company_uuid)) {
            return;
        }

        try {
            $this->walletService->provisionUserWallet($user);
        } catch (\Throwable $e) {
            Log::error('[Ledger] Failed to provision wallet for user ' . $user->uuid . ': ' . $e->getMessage());
        }
    }
}
