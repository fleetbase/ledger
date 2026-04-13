<?php

namespace Fleetbase\Ledger\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\SoftDeletes;

class GlExportBatch extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use SoftDeletes;

    protected $table = 'gl_export_batches';
    protected $publicIdType = 'gl_export';

    protected $fillable = [
        'company_uuid', 'format', 'status', 'period_start', 'period_end',
        'file_uuid', 'record_count', 'total_amount', 'exported_at', 'meta',
    ];

    protected $casts = [
        'period_start'  => 'date',
        'period_end'    => 'date',
        'exported_at'   => 'datetime',
        'total_amount'  => 'decimal:2',
        'record_count'  => 'integer',
        'meta'          => Json::class,
    ];

    public function company()
    {
        return $this->belongsTo(\Fleetbase\Models\Company::class, 'company_uuid', 'uuid');
    }
}
