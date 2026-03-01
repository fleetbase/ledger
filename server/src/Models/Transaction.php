<?php

namespace Fleetbase\Ledger\Models;

use Fleetbase\Models\Transaction as BaseTransaction;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Ledger Transaction model.
 *
 * Extends the core-api Transaction model to add the journal entry relationship.
 * This allows the Ledger Transaction resource to include journal data without
 * any controller-level overrides.
 */
class Transaction extends BaseTransaction
{
    /**
     * The journal entry associated with this transaction, if one exists.
     */
    public function journal(): HasOne
    {
        return $this->hasOne(Journal::class, 'transaction_uuid', 'uuid');
    }
}
