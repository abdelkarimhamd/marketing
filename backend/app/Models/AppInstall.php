<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AppInstall extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'marketplace_app_id',
        'installed_by',
        'status',
        'config_json',
        'installed_at',
        'uninstalled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config_json' => 'array',
            'installed_at' => 'datetime',
            'uninstalled_at' => 'datetime',
        ];
    }

    /**
     * Marketplace app relation.
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(MarketplaceApp::class, 'marketplace_app_id');
    }

    /**
     * Installer relation.
     */
    public function installer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'installed_by');
    }

    /**
     * Secret rows.
     */
    public function secrets(): HasMany
    {
        return $this->hasMany(AppSecret::class);
    }

    /**
     * Webhook configurations.
     */
    public function webhooks(): HasMany
    {
        return $this->hasMany(AppWebhook::class);
    }
}
