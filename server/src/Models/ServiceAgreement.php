<?php

namespace Fleetbase\Ledger\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasComments;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceAgreement extends Model
{
    use HasUuid, HasPublicId, HasApiModelBehavior, HasComments, Searchable, SoftDeletes;

    protected $table = 'service_agreements';
    protected $publicIdType = 'sa';
    protected $searchableColumns = ['name'];

    protected $fillable = [
        'company_uuid', 'customer_uuid', 'name', 'status',
        'billing_frequency', 'payment_terms_days',
        'effective_date', 'expiration_date',
        'currency', 'notes', 'meta',
    ];

    protected $casts = [
        'payment_terms_days' => 'integer',
        'effective_date'     => 'date',
        'expiration_date'    => 'date',
        'meta'               => Json::class,
    ];

    public function company()
    {
        return $this->belongsTo(\Fleetbase\Models\Company::class, 'company_uuid', 'uuid');
    }

    public function customer()
    {
        return $this->belongsTo(\Fleetbase\FleetOps\Models\Contact::class, 'customer_uuid', 'uuid');
    }

    public function charges()
    {
        return $this->hasMany(ServiceAgreementCharge::class, 'service_agreement_uuid', 'uuid');
    }

    public function clientInvoices()
    {
        return $this->hasMany(ClientInvoice::class, 'service_agreement_uuid', 'uuid');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeEffective($query)
    {
        return $query->where('effective_date', '<=', now())
            ->where(function ($q) {
                $q->whereNull('expiration_date')
                  ->orWhere('expiration_date', '>=', now());
            });
    }

    public function scopeForCustomer($query, string $customerUuid)
    {
        return $query->where('customer_uuid', $customerUuid);
    }
}
