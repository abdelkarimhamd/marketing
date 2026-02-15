<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSandbox extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'sandbox_tenant_id',
        'name',
        'status',
        'anonymized',
        'last_cloned_at',
        'last_promoted_at',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'anonymized' => 'boolean',
            'last_cloned_at' => 'datetime',
            'last_promoted_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function sandboxTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'sandbox_tenant_id');
    }
}

