<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Ledger\Models\CarrierInvoiceAuditRule;

class CarrierInvoiceAuditRuleController extends FleetbaseController
{
    public $resource = CarrierInvoiceAuditRule::class;
}
