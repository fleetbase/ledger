<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Ledger\Models\ServiceAgreement;

class ServiceAgreementController extends FleetbaseController
{
    public $resource = ServiceAgreement::class;
}
