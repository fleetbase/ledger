<?php

namespace Fleetbase\Ledger\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasUuid;

class CarrierInvoiceItem extends Model
{
    use HasUuid;
    use HasApiModelBehavior;

    protected $table = 'carrier_invoice_items';

    protected $fillable = [
        'carrier_invoice_uuid', 'charge_type', 'description', 'accessorial_code',
        'invoiced_amount', 'planned_amount', 'approved_amount', 'discrepancy_amount',
        'quantity', 'rate', 'rate_type', 'meta',
    ];

    protected $casts = [
        'invoiced_amount'    => 'decimal:2',
        'planned_amount'     => 'decimal:2',
        'approved_amount'    => 'decimal:2',
        'discrepancy_amount' => 'decimal:2',
        'quantity'           => 'decimal:2',
        'rate'               => 'decimal:4',
        'meta'               => Json::class,
    ];

    public function carrierInvoice()
    {
        return $this->belongsTo(CarrierInvoice::class, 'carrier_invoice_uuid', 'uuid');
    }
}
