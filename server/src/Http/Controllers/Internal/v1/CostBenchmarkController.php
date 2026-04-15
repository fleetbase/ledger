<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Ledger\Models\CostBenchmark;

class CostBenchmarkController extends FleetbaseController
{
    public $resource = CostBenchmark::class;
}
