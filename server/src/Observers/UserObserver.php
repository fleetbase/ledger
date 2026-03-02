<?php

namespace Fleetbase\Ledger\Observers;

use Fleetbase\Ledger\Services\WalletService;
use Fleetbase\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * UserObserver.
 *
 * Listens on the core User model and automatically provisions a personal
 * Ledger wallet whenever a user's type is set to 'driver' or 'customer'.
 *
 * Because users are typically created with type = 'user' and then upgraded
 * to 'driver' or 'customer' via a subsequent setType() call (which triggers
 * an `updated` event), we listen on both `created` and `updated` and only
 * act when the type has just changed to a wallet-eligible value.
 *
 * Wallets are scoped to the user's company (company_uuid) so that a user who
 * belongs to multiple companies receives a separate wallet per company.
 *
 * Admin and regular 'user' type accounts do not receive a wallet automatically.
 *
 * The provisioning call is independently try/caught so a failure does not abort
 * the user save.
 */
class UserObserver
{
    public function __construct(protected WalletService $walletService) {}

    /**
     * Handle the User "created" event.
     *
     * Handles the rare case where a user is created directly with type = 'driver'
     * or 'customer' (e.g. via seeder or API import).
     */
    public function created(User $user): void
    {
        if (!in_array($user->type, ['driver', 'customer'])) {
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
     * The normal flow is: user created with type='user', then DriverController
     * or ContactController calls $user->setType('driver'|'customer') which saves
     * and fires this event. We check wasChanged('type') to only act on the type
     * transition, and only when the new type is wallet-eligible.
     */
    public function updated(User $user): void
    {
        // Only act when the type column was just changed in this save
        if (!$user->wasChanged('type')) {
            return;
        }

        if (!in_array($user->type, ['driver', 'customer'])) {
            return;
        }

        try {
            $this->walletService->provisionUserWallet($user);
        } catch (\Throwable $e) {
            Log::error('[Ledger] Failed to provision wallet for user ' . $user->uuid . ': ' . $e->getMessage());
        }
    }
}
