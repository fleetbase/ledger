<?php

namespace Fleetbase\Ledger\Observers;

use Fleetbase\Ledger\Services\WalletService;
use Fleetbase\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * UserObserver.
 *
 * Listens on the core User model and automatically provisions a personal
 * Ledger wallet whenever a new user with type 'driver' or 'customer' is created.
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
     */
    public function created(User $user): void
    {
        try {
            $this->walletService->provisionUserWallet($user);
        } catch (\Throwable $e) {
            Log::error('[Ledger] Failed to provision wallet for user ' . $user->uuid . ': ' . $e->getMessage());
        }
    }
}
