<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Ledger\Models\ChargeTemplate;

class ChargeTemplateController extends FleetbaseController
{
    public $resource = ChargeTemplate::class;
}
