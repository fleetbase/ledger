<?php

namespace Fleetbase\Ledger\Models;

use Carbon\Carbon;
use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayFileSchedule extends Model
{
    use HasUuid, HasPublicId, HasApiModelBehavior, SoftDeletes;

    public const FREQUENCY_WEEKLY   = 'weekly';
    public const FREQUENCY_BIWEEKLY = 'biweekly';
    public const FREQUENCY_MONTHLY  = 'monthly';

    protected $table = 'pay_file_schedules';
    protected $publicIdType = 'pf_sched';

    protected $fillable = [
        'company_uuid', 'name', 'format', 'frequency',
        'day_of_week', 'day_of_month',
        'auto_send', 'recipients', 'is_active',
        'last_run_at', 'next_run_at', 'meta',
    ];

    protected $casts = [
        'day_of_week'  => 'integer',
        'day_of_month' => 'integer',
        'auto_send'    => 'boolean',
        'is_active'    => 'boolean',
        'recipients'   => Json::class,
        'last_run_at'  => 'datetime',
        'next_run_at'  => 'datetime',
        'meta'         => Json::class,
    ];

    public function company()
    {
        return $this->belongsTo(\Fleetbase\Models\Company::class, 'company_uuid', 'uuid');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDueForRun($query)
    {
        return $query->where('next_run_at', '<=', now());
    }

    /**
     * Calculate the next run timestamp based on frequency.
     */
    public function calculateNextRun(?Carbon $from = null): Carbon
    {
        $base = $from ?? now();

        return match ($this->frequency) {
            self::FREQUENCY_WEEKLY   => $base->copy()->addWeek(),
            self::FREQUENCY_BIWEEKLY => $base->copy()->addWeeks(2),
            self::FREQUENCY_MONTHLY  => $base->copy()->addMonth(),
            default                  => $base->copy()->addWeek(),
        };
    }
}
