<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Ledger\Http\Controllers\LedgerResourceController;

/**
 * WalletTransactionController.
 *
 * Provides a company-scoped query/find endpoint for wallet transactions
 * across all wallets. All CRUD is handled by HasApiControllerBehavior via
 * LedgerResourceController; the WalletTransactionFilter handles company scoping
 * and parameter filtering automatically.
 */
class WalletTransactionController extends LedgerResourceController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'wallet-transaction';
}
