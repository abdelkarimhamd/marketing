<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Support\DomainHost;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetTenant
{
    private const SESSION_KEY = 'tenancy.switch_tenant_id';

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(TenantContext::class);
        $user = $this->resolveAuthenticatedUser($request);
        [$switchWasRequested, $requestedTenantId] = $this->resolveRequestedTenantId($request);

        if ($user?->isSuperAdmin()) {
            $this->applySuperAdminContext($request, $context, $switchWasRequested, $requestedTenantId);
        } else {
            $this->applyStandardContext($request, $context, $user, $switchWasRequested, $requestedTenantId);
        }

        $request->attributes->set('tenant_id', $context->tenantId());
        $request->attributes->set('tenant_bypassed', $context->isBypassed());

        $tenantId = $context->tenantId();

        if (is_int($tenantId) && $tenantId > 0) {
            $tenant = Tenant::query()
                ->whereKey($tenantId)
                ->first(['locale', 'timezone']);

            $locale = is_string($tenant?->locale) ? trim($tenant->locale) : '';
            $timezone = is_string($tenant?->timezone) ? trim($tenant->timezone) : '';

            if ($locale !== '') {
                App::setLocale($locale);
            }

            if ($timezone !== '') {
                try {
                    date_default_timezone_set($timezone);
                } catch (\Throwable) {
                    // Ignore invalid timezone values and keep app default.
                }
            }
        }

        if ($user instanceof User && ! $user->isSuperAdmin() && $user->tenant_id !== null) {
            $user->forceFill([
                'last_seen_at' => now(),
            ])->saveQuietly();
        }

        return $next($request);
    }

    /**
     * Resolve tenant context for a super-admin request.
     */
    private function applySuperAdminContext(
        Request $request,
        TenantContext $context,
        bool $switchWasRequested,
        ?int $requestedTenantId
    ): void {
        if ($switchWasRequested) {
            if ($requestedTenantId === null) {
                if ($request->hasSession()) {
                    $request->session()->forget(self::SESSION_KEY);
                }

                $context->bypass();

                return;
            }

            $tenantId = $this->ensureTenantExists($requestedTenantId);

            if ($request->hasSession()) {
                $request->session()->put(self::SESSION_KEY, $tenantId);
            }

            $context->setTenant($tenantId);

            return;
        }

        if ($request->hasSession() && $request->session()->has(self::SESSION_KEY)) {
            $tenantId = (int) $request->session()->get(self::SESSION_KEY);

            if (Tenant::query()->whereKey($tenantId)->exists()) {
                $context->setTenant($tenantId);

                return;
            }

            $request->session()->forget(self::SESSION_KEY);
        }

        $context->bypass();
    }

    /**
     * Resolve tenant context for non super-admin requests.
     */
    private function applyStandardContext(
        Request $request,
        TenantContext $context,
        ?User $user,
        bool $switchWasRequested,
        ?int $requestedTenantId
    ): void {
        if ($user !== null) {
            $tenantId = $user->tenant_id !== null ? (int) $user->tenant_id : null;

            if ($switchWasRequested && $requestedTenantId !== null && $tenantId !== $requestedTenantId) {
                abort(403, 'Tenant switching is only available to super-admin users.');
            }

            if ($tenantId !== null) {
                $context->setTenant($tenantId);

                return;
            }

            $context->clear();

            return;
        }

        if ($switchWasRequested && $requestedTenantId !== null) {
            $context->setTenant($this->ensureTenantExists($requestedTenantId));

            return;
        }

        $hostTenantId = $this->resolveTenantIdFromHost($request);

        if ($hostTenantId !== null) {
            $context->setTenant($hostTenantId);

            return;
        }

        $context->clear();
    }

    /**
     * Parse requested tenant switch from header/query/body.
     *
     * @return array{bool, int|null}
     */
    private function resolveRequestedTenantId(Request $request): array
    {
        $value = null;
        $provided = false;

        if ($request->headers->has('X-Tenant-ID')) {
            $value = $request->header('X-Tenant-ID');
            $provided = true;
        } elseif ($request->query->has('tenant_id')) {
            $value = $request->query('tenant_id');
            $provided = true;
        } elseif ($request->request->has('tenant_id')) {
            $value = $request->input('tenant_id');
            $provided = true;
        }

        if (! $provided) {
            return [false, null];
        }

        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === null || $value === '' || $value === '*' || strtolower((string) $value) === 'all') {
            return [true, null];
        }

        if (! is_numeric($value) || (int) $value <= 0) {
            abort(422, 'Invalid tenant_id value.');
        }

        return [true, (int) $value];
    }

    /**
     * Ensure a tenant exists before setting context.
     */
    private function ensureTenantExists(int $tenantId): int
    {
        if (! Tenant::query()->whereKey($tenantId)->exists()) {
            abort(404, 'Tenant not found.');
        }

        return $tenantId;
    }

    /**
     * Resolve authenticated user for session and token guards.
     */
    private function resolveAuthenticatedUser(Request $request): ?User
    {
        $user = $request->user();

        if ($user instanceof User) {
            return $user;
        }

        $sanctumUser = Auth::guard('sanctum')->user();

        if ($sanctumUser instanceof User) {
            return $sanctumUser;
        }

        $webUser = Auth::guard('web')->user();

        return $webUser instanceof User ? $webUser : null;
    }

    /**
     * Resolve tenant by request host using verified custom domains first.
     */
    private function resolveTenantIdFromHost(Request $request): ?int
    {
        $host = DomainHost::normalize($request->getHost());

        if ($host === null || DomainHost::isLocalHost($host)) {
            return null;
        }

        $mappedTenantId = TenantDomain::query()
            ->withoutTenancy()
            ->where('host', $host)
            ->where('verification_status', TenantDomain::VERIFICATION_VERIFIED)
            ->whereHas('tenant', fn ($query) => $query->where('is_active', true))
            ->value('tenant_id');

        if (is_numeric($mappedTenantId) && (int) $mappedTenantId > 0) {
            return (int) $mappedTenantId;
        }

        $legacyTenantId = Tenant::query()
            ->where('domain', $host)
            ->where('is_active', true)
            ->value('id');

        return is_numeric($legacyTenantId) && (int) $legacyTenantId > 0
            ? (int) $legacyTenantId
            : null;
    }
}
