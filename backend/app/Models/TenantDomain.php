<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantDomain extends Model
{
    use BelongsToTenant, HasFactory;

    public const KIND_ADMIN = 'admin';
    public const KIND_LANDING = 'landing';

    public const VERIFICATION_PENDING = 'pending';
    public const VERIFICATION_VERIFIED = 'verified';
    public const VERIFICATION_FAILED = 'failed';

    public const SSL_PENDING = 'pending';
    public const SSL_PROVISIONING = 'provisioning';
    public const SSL_ACTIVE = 'active';
    public const SSL_FAILED = 'failed';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'host',
        'kind',
        'is_primary',
        'cname_target',
        'verification_token',
        'verification_status',
        'verified_at',
        'verification_error',
        'ssl_status',
        'ssl_provider',
        'ssl_expires_at',
        'ssl_last_checked_at',
        'ssl_error',
        'metadata',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
            'ssl_expires_at' => 'datetime',
            'ssl_last_checked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * Tenant owning this domain.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Determine whether this host has passed CNAME verification.
     */
    public function isVerified(): bool
    {
        return $this->verification_status === self::VERIFICATION_VERIFIED;
    }
}

