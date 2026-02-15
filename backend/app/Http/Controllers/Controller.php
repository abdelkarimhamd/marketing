<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Authorize one tenant-scoped permission.
     */
    protected function authorizePermission(
        Request $request,
        string $permission,
        bool $requireTenantContext = true
    ): void {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401, 'Authentication is required.');
        }

        $tenantId = $this->resolveTenantIdForPermission($request, $user);

        if ($requireTenantContext && ! $user->isSuperAdmin() && $tenantId === null) {
            abort(422, 'Tenant context is required for this operation.');
        }

        if (! $user->hasPermission($permission, $tenantId)) {
            abort(403, 'You do not have permission for this operation.');
        }
    }

    /**
     * Resolve tenant id from request context, input or user profile.
     */
    protected function resolveTenantIdForPermission(Request $request, ?User $user = null): ?int
    {
        $tenantId = $request->attributes->get('tenant_id');

        if (is_int($tenantId) && $tenantId > 0) {
            return $tenantId;
        }

        $requestTenantId = $request->query('tenant_id', $request->input('tenant_id'));

        if (is_numeric($requestTenantId) && (int) $requestTenantId > 0) {
            return (int) $requestTenantId;
        }

        if ($user !== null && $user->tenant_id !== null) {
            return (int) $user->tenant_id;
        }

        return null;
    }
}
