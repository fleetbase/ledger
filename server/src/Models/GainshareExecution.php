<?php

namespace Fleetbase\Ledger\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\SoftDeletes;

class GainshareExecution extends Model
{
    use HasUuid, HasPublicId, HasApiModelBehavior, SoftDeletes;

    protected $table = 'gainshare_executions';
    protected $publicIdType = 'gs_exec';

    /**
     * Result type classifications:
     * - savings: positive savings above threshold, shares calculated
     * - loss: actual cost exceeded benchmark, shares = 0
     * - break_even: actual cost equals benchmark, shares = 0
     * - below_threshold: positive savings but below minimum threshold, shares = 0
     */
    public const RESULT_SAVINGS         = 'savings';
    public const RESULT_LOSS            = 'loss';
    public const RESULT_BREAK_EVEN      = 'break_even';
    public const RESULT_BELOW_THRESHOLD = 'below_threshold';

    protected $fillable = [
        'company_uuid', 'gainshare_rule_uuid',
        'shipment_uuid', 'carrier_invoice_uuid', 'client_invoice_uuid',
        'cost_benchmark_uuid',
        'benchmark_total', 'actual_total', 'savings',
        'company_share', 'client_share', 'result_type',
        'status', 'period_start', 'period_end', 'meta',
    ];

    protected $casts = [
        'benchmark_total' => 'decimal:2',
        'actual_total'    => 'decimal:2',
        'savings'         => 'decimal:2',
        'company_share'   => 'decimal:2',
        'client_share'    => 'decimal:2',
        'period_start'    => 'date',
        'period_end'      => 'date',
        'meta'            => Json::class,
    ];

    public function company()
    {
        return $this->belongsTo(\Fleetbase\Models\Company::class, 'company_uuid', 'uuid');
    }

    public function gainshareRule()
    {
        return $this->belongsTo(GainshareRule::class, 'gainshare_rule_uuid', 'uuid');
    }

    public function shipment()
    {
        return $this->belongsTo(\Fleetbase\FleetOps\Models\Shipment::class, 'shipment_uuid', 'uuid');
    }

    public function carrierInvoice()
    {
        return $this->belongsTo(CarrierInvoice::class, 'carrier_invoice_uuid', 'uuid');
    }

    public function clientInvoice()
    {
        return $this->belongsTo(ClientInvoice::class, 'client_invoice_uuid', 'uuid');
    }

    public function costBenchmark()
    {
        return $this->belongsTo(CostBenchmark::class, 'cost_benchmark_uuid', 'uuid');
    }
}
