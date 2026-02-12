<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiKey extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'created_by',
        'name',
        'prefix',
        'key_hash',
        'secret',
        'abilities',
        'settings',
        'last_used_at',
        'expires_at',
        'revoked_at',
    ];

    /**
     * Attributes hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'key_hash',
        'secret',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'abilities' => 'array',
            'settings' => 'encrypted:array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * User that created the API key.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope to active API keys.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where(function (Builder $builder): void {
                $builder
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Find an active API key from the provided plaintext key.
     */
    public static function findActiveByPlainText(string $plainTextKey): ?self
    {
        return self::query()
            ->withoutTenancy()
            ->active()
            ->where('key_hash', hash('sha256', $plainTextKey))
            ->first();
    }
}
