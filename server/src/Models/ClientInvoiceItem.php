<?php

namespace Fleetbase\Ledger\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientInvoiceItem extends Model
{
    use HasUuid, HasApiModelBehavior, SoftDeletes;

    protected $table = 'client_invoice_items';

    protected $fillable = [
        'client_invoice_uuid', 'charge_type', 'description',
        'calculation_method', 'rate', 'quantity', 'amount',
        'shipment_uuid', 'meta',
    ];

    protected $casts = [
        'rate'     => 'decimal:4',
        'quantity' => 'decimal:2',
        'amount'   => 'decimal:2',
        'meta'     => Json::class,
    ];

    public function clientInvoice()
    {
        return $this->belongsTo(ClientInvoice::class, 'client_invoice_uuid', 'uuid');
    }
}
