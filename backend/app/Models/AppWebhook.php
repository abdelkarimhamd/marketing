<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AppWebhook extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'app_install_id',
        'endpoint_url',
        'events_json',
        'settings',
        'is_active',
        'last_delivered_at',
        'last_error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'events_json' => 'array',
            'settings' => 'array',
            'is_active' => 'boolean',
            'last_delivered_at' => 'datetime',
        ];
    }

    /**
     * Install relation.
     */
    public function install(): BelongsTo
    {
        return $this->belongsTo(AppInstall::class, 'app_install_id');
    }

    /**
     * Delivery logs.
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(AppWebhookDelivery::class);
    }
}
