<?php

namespace Fleetbase\Ledger\Events;

use Fleetbase\Ledger\Models\CarrierInvoice;

class CarrierInvoiceReceived
{
    public CarrierInvoice $carrierInvoice;

    public function __construct(CarrierInvoice $carrierInvoice)
    {
        $this->carrierInvoice = $carrierInvoice;
    }
}
