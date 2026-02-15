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

        RateLimiter::for('public-portal', function (Request $request): array {
            $tenantKey = (string) (
                $request->attributes->get('tenant_id')
                ?? $request->header('X-Tenant-ID')
                ?? $request->header('X-Tenant-Slug')
                ?? 'unknown'
            );

            return [
                Limit::perMinute(40)->by('public-portal:tenant:'.$tenantKey),
                Limit::perMinute(40)->by('public-portal:ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('public-portal-upload', function (Request $request): array {
            $tenantKey = (string) (
                $request->attributes->get('tenant_id')
                ?? $request->header('X-Tenant-ID')
                ?? $request->header('X-Tenant-Slug')
                ?? 'unknown'
            );

            return [
                Limit::perMinute(12)->by('public-portal-upload:tenant:'.$tenantKey),
                Limit::perMinute(12)->by('public-portal-upload:ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('public-portal-status', function (Request $request): array {
            $token = (string) $request->route('token', 'unknown');

            return [
                Limit::perMinute(120)->by('public-portal-status:token:'.$token),
                Limit::perMinute(120)->by('public-portal-status:ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('public-chat-widget', function (Request $request): array {
            $tenantKey = (string) (
                $request->attributes->get('tenant_id')
                ?? $request->header('X-Tenant-ID')
                ?? $request->header('X-Tenant-Slug')
                ?? 'unknown'
            );

            return [
                Limit::perMinute(120)->by('public-chat-widget:tenant:'.$tenantKey),
                Limit::perMinute(120)->by('public-chat-widget:ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('public-chat', function (Request $request): array {
            $tenantKey = (string) (
                $request->attributes->get('tenant_id')
                ?? $request->header('X-Tenant-ID')
                ?? $request->header('X-Tenant-Slug')
                ?? 'unknown'
            );

            return [
                Limit::perMinute(90)->by('public-chat:tenant:'.$tenantKey),
                Limit::perMinute(90)->by('public-chat:ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('public-track', function (Request $request): array {
            $tenantKey = (string) ($request->input('tenant_key') ?? $request->header('X-Tenant-Key') ?? 'unknown');

            return [
                Limit::perMinute(240)->by('public-track:tenant:'.$tenantKey),
                Limit::perMinute(240)->by('public-track:ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('public-identify', function (Request $request): array {
            $tenantKey = (string) ($request->input('tenant_key') ?? $request->header('X-Tenant-Key') ?? 'unknown');

            return [
                Limit::perMinute(90)->by('public-identify:tenant:'.$tenantKey),
                Limit::perMinute(90)->by('public-identify:ip:'.$request->ip()),
            ];
        });

        RateLimiter::for(
            'unsubscribe',
            fn (Request $request): Limit => Limit::perMinute(40)->by('unsubscribe:'.$request->ip())
        );

        RateLimiter::for(
            'public-signup',
            fn (Request $request): Limit => Limit::perMinute(10)->by('public-signup:'.$request->ip())
        );

        RateLimiter::for(
            'scim',
            fn (Request $request): Limit => Limit::perMinute(180)->by(
                'scim:'.($request->header('Authorization') ?? $request->ip())
            )
        );

        Gate::policy(User::class, UserPolicy::class);

        Gate::define(
            'admin.access',
            fn (User $user): bool => $user->isSuperAdmin() || $user->isTenantAdmin() || $user->hasAnyPermission()
        );

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
