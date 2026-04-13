<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Ledger\Models\GlAssignment;

class GlAssignmentController extends FleetbaseController
{
    public $resource = GlAssignment::class;
}
