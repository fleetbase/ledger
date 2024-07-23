<?php

namespace Fleetbase\Ledger\Http\Controllers;

use Fleetbase\Http\Controllers\FleetbaseController;

class LedgerResourceController extends FleetbaseController
{
    /**
     * The package namespace used to resolve from.
     */
    public string $namespace = '\\Fleetbase\\Ledger';
}
