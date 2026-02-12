<?php

namespace App\Providers;

use App\Models\Tenant;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, fn (): TenantContext => new TenantContext());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('public-leads', function (Request $request): array {
            $tenantKey = (string) (
                $request->attributes->get('tenant_id')
                ?? $request->header('X-Tenant-ID')
                ?? $request->header('X-Tenant-Slug')
                ?? 'unknown'
            );

            return [
                Limit::perMinute(30)->by('public-leads:tenant:'.$tenantKey),
                Limit::perMinute(30)->by('public-leads:ip:'.$request->ip()),
            ];
        });

        RateLimiter::for(
            'unsubscribe',
            fn (Request $request): Limit => Limit::perMinute(40)->by('unsubscribe:'.$request->ip())
        );

        Gate::policy(User::class, UserPolicy::class);

        Gate::define('admin.access', fn (User $user): bool => $user->isAdmin());

        Gate::define(
            'tenant.access',
            function (User $user, ?Tenant $tenant = null): bool {
                if ($user->isSuperAdmin()) {
                    return true;
                }

                return $tenant !== null && $user->belongsToTenant($tenant->id);
            }
        );

        Gate::define(
            'tenant.manage',
            function (User $user, ?Tenant $tenant = null): bool {
                if ($user->isSuperAdmin()) {
                    return true;
                }

                return $user->isTenantAdmin()
                    && $tenant !== null
                    && $user->belongsToTenant($tenant->id);
            }
        );
    }
}
