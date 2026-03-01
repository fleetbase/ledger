<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Ledger\Http\Controllers\LedgerController;

/**
 * WalletTransactionController.
 *
 * Provides a company-scoped query/find endpoint for wallet transactions
 * across all wallets. All CRUD is handled by HasApiControllerBehavior via
 * LedgerController; the WalletTransactionFilter handles company scoping
 * and parameter filtering automatically.
 */
class WalletTransactionController extends LedgerController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'wallet-transaction';
}
