<?php

namespace Fleetbase\Ledger\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayFileItem extends Model
{
    use HasUuid, HasApiModelBehavior, SoftDeletes;

    protected $table = 'pay_file_items';

    protected $fillable = [
        'pay_file_uuid', 'carrier_invoice_uuid', 'vendor_uuid',
        'amount', 'payment_method', 'reference_number', 'meta',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'meta'   => Json::class,
    ];

    public function payFile()
    {
        return $this->belongsTo(PayFile::class, 'pay_file_uuid', 'uuid');
    }

    public function carrierInvoice()
    {
        return $this->belongsTo(CarrierInvoice::class, 'carrier_invoice_uuid', 'uuid');
    }

    public function vendor()
    {
        return $this->belongsTo(\Fleetbase\FleetOps\Models\Vendor::class, 'vendor_uuid', 'uuid');
    }
}
