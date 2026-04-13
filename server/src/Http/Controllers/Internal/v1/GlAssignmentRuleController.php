<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Ledger\Models\GlAssignmentRule;

class GlAssignmentRuleController extends FleetbaseController
{
    public $resource = GlAssignmentRule::class;
}
