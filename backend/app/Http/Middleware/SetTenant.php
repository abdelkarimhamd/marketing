<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
            $this->applyStandardContext($context, $user, $switchWasRequested, $requestedTenantId);
        }

        $request->attributes->set('tenant_id', $context->tenantId());
        $request->attributes->set('tenant_bypassed', $context->isBypassed());

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
}
