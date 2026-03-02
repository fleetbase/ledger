<?php

namespace Fleetbase\Ledger\Models;

use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Gateway Model.
 *
 * Represents a configured payment gateway instance.
 * Credentials are stored encrypted at rest via the 'encrypted:array' cast.
 *
 * @property string      $uuid
 * @property string      $public_id
 * @property string      $company_uuid
 * @property string      $created_by_uuid
 * @property string      $name
 * @property string      $driver
 * @property string|null $description
 * @property array|null  $config
 * @property array|null  $capabilities
 * @property bool        $is_sandbox
 * @property string      $status
 * @property string|null $return_url
 * @property string|null $webhook_url
 */
class Gateway extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use SoftDeletes;

    /**
     * The database table used by the model.
     */
    protected $table = 'ledger_gateways';

    /**
     * The public ID prefix for this model.
     */
    protected $publicIdPrefix = 'gateway';

    /**
     * The response payload key to use.
     */
    protected $payloadKey = 'gateway';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'uuid',
        'public_id',
        'company_uuid',
        'created_by_uuid',
        'name',
        'driver',
        'description',
        'config',
        'capabilities',
        'is_sandbox',
        'status',
        'return_url',
        'webhook_url',
    ];

    /**
     * The attributes that should be cast.
     *
     * The 'config' column is cast to 'encrypted:array' so that credentials
     * (API keys, secrets) are always encrypted at rest in the database.
     */
    protected $casts = [
        'config'       => 'encrypted:array',
        'capabilities' => 'array',
        'is_sandbox'   => 'boolean',
    ];

    /**
     * The attributes that should be appended to the model's array form.
     */
    protected $appends = ['public_id'];

    /**
     * The attributes that should be hidden for serialization.
     * The config (credentials) are never exposed via the API.
     */
    protected $hidden = ['config'];

    /**
     * The attributes that should be visible for serialization.
     */
    protected $visible = [
        'uuid',
        'public_id',
        'company_uuid',
        'name',
        'driver',
        'description',
        'capabilities',
        'is_sandbox',
        'status',
        'return_url',
        'webhook_url',
        'created_at',
        'updated_at',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * The gateway transactions associated with this gateway.
     */
    public function transactions()
    {
        return $this->hasMany(GatewayTransaction::class, 'gateway_uuid', 'uuid');
    }

    // -------------------------------------------------------------------------
    // Accessors & Helpers
    // -------------------------------------------------------------------------

    /**
     * Return the decrypted configuration array.
     * This is used internally by the PaymentGatewayManager to initialize drivers.
     */
    public function decryptedConfig(): array
    {
        return $this->config ?? [];
    }

    /**
     * Check if this gateway has a specific capability.
     */
    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities ?? [], true);
    }

    /**
     * Check if this gateway is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Scope to only active gateways.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to filter by driver code.
     */
    public function scopeForDriver($query, string $driver)
    {
        return $query->where('driver', $driver);
    }

    /**
     * Scope to filter by company.
     */
    public function scopeForCompany($query, string $companyUuid)
    {
        return $query->where('company_uuid', $companyUuid);
    }

    /**
     * Generate the webhook URL for this gateway.
     * This is the URL that should be registered in the gateway's dashboard.
     */
    public function getWebhookUrl(): string
    {
        return url('/ledger/webhooks/' . $this->driver);
    }
}
