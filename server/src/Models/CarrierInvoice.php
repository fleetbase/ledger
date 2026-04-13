<?php

namespace Fleetbase\Ledger\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasComments;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Fleetbase\Ledger\Traits\HasGlAssignments;
use Illuminate\Database\Eloquent\SoftDeletes;

class CarrierInvoice extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use HasComments;
    use HasGlAssignments;
    use Searchable;
    use SoftDeletes;

    protected $table = 'carrier_invoices';
    protected $publicIdType = 'cinv';
    protected $searchableColumns = ['invoice_number', 'pro_number', 'bol_number'];

    protected $fillable = [
        'company_uuid', 'vendor_uuid', 'order_uuid', 'shipment_uuid',
        'invoice_number', 'pro_number', 'bol_number',
        'source', 'status',
        'invoiced_amount', 'planned_amount', 'approved_amount',
        'discrepancy_amount', 'discrepancy_percent',
        'discrepancy_type', 'resolution', 'resolution_notes',
        'resolved_by', 'resolved_at',
        'invoice_date', 'due_date', 'received_at',
        'pickup_date', 'delivery_date',
        'currency', 'file_uuid', 'meta',
    ];

    protected $casts = [
        'invoiced_amount'     => 'decimal:2',
        'planned_amount'      => 'decimal:2',
        'approved_amount'     => 'decimal:2',
        'discrepancy_amount'  => 'decimal:2',
        'discrepancy_percent' => 'decimal:2',
        'invoice_date'        => 'date',
        'due_date'            => 'date',
        'pickup_date'         => 'date',
        'delivery_date'       => 'date',
        'received_at'         => 'datetime',
        'resolved_at'         => 'datetime',
        'meta'                => Json::class,
    ];

    public function company()
    {
        return $this->belongsTo(\Fleetbase\Models\Company::class, 'company_uuid', 'uuid');
    }

    public function vendor()
    {
        return $this->belongsTo(\Fleetbase\FleetOps\Models\Vendor::class, 'vendor_uuid', 'uuid');
    }

    public function order()
    {
        return $this->belongsTo(\Fleetbase\FleetOps\Models\Order::class, 'order_uuid', 'uuid');
    }

    public function items()
    {
        return $this->hasMany(CarrierInvoiceItem::class, 'carrier_invoice_uuid', 'uuid');
    }

    public function resolvedBy()
    {
        return $this->belongsTo(\Fleetbase\Models\User::class, 'resolved_by', 'uuid');
    }

    public function file()
    {
        return $this->belongsTo(\Fleetbase\Models\File::class, 'file_uuid', 'uuid');
    }

    public function getGlContext(): array
    {
        $context = [
            'carrier' => $this->vendor_uuid,
        ];

        if ($this->order) {
            $context['customer']       = $this->order->customer_uuid ?? null;
            $context['mode']           = $this->order->meta['mode'] ?? null;
            $context['equipment_type'] = $this->order->meta['equipment_type'] ?? null;

            $pickup  = $this->order->payload?->pickup;
            $dropoff = $this->order->payload?->dropoff;
            if ($pickup) {
                $context['origin_state'] = $pickup->state ?? null;
                $context['origin_zip']   = $pickup->postal_code ?? null;
            }
            if ($dropoff) {
                $context['dest_state'] = $dropoff->state ?? null;
                $context['dest_zip']   = $dropoff->postal_code ?? null;
            }
        }

        return $context;
    }
}
