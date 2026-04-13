<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Ledger\Models\GainshareRule;

class GainshareRuleController extends FleetbaseController
{
    public $resource = GainshareRule::class;
}
