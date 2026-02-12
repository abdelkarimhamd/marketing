<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    /**
     * Boot the tenant ownership trait for a model.
     */
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (Model $model): void {
            if ($model->getAttribute('tenant_id') !== null) {
                return;
            }

            if (method_exists($model, 'isSuperAdmin') && $model->isSuperAdmin()) {
                return;
            }

            $context = app(TenantContext::class);

            if (! $context->hasTenant()) {
                return;
            }

            $model->setAttribute('tenant_id', $context->tenantId());
        });
    }

    /**
     * Get the tenant that owns the model.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Query model rows for a specific tenant.
     */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query
            ->withoutGlobalScope(TenantScope::class)
            ->where($query->getModel()->qualifyColumn('tenant_id'), $tenantId);
    }

    /**
     * Remove tenant scope filtering from a query.
     */
    public function scopeWithoutTenancy(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}
