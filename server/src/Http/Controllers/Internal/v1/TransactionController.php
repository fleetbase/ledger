<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Ledger\Http\Controllers\LedgerController;

/**
 * TransactionController.
 *
 * Read-only view of Ledger Transaction records (which extend the core-api
 * Transaction model with a journal relationship). All CRUD and filtering is
 * handled by HasApiControllerBehavior via LedgerController. The TransactionFilter
 * applies company scoping. The Transaction resource includes the journal
 * relationship via whenLoaded().
 */
class TransactionController extends LedgerController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'transaction';
}
