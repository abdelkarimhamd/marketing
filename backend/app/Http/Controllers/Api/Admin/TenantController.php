<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    /**
     * Return tenants available to the current user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->isAdmin()) {
            abort(403, 'Admin permissions are required.');
        }

        if ($user->isSuperAdmin()) {
            $tenants = Tenant::query()
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'domain', 'is_active', 'created_at', 'updated_at']);

            return response()->json([
                'data' => $tenants,
                'active_tenant_id' => $request->attributes->get('tenant_id'),
                'tenant_bypassed' => (bool) $request->attributes->get('tenant_bypassed', false),
            ]);
        }

        if ($user->tenant_id === null) {
            return response()->json([
                'data' => [],
                'active_tenant_id' => null,
                'tenant_bypassed' => false,
            ]);
        }

        $tenant = Tenant::query()
            ->whereKey($user->tenant_id)
            ->first();

        return response()->json([
            'data' => $tenant ? [$tenant->only(['id', 'name', 'slug', 'domain', 'is_active', 'created_at', 'updated_at'])] : [],
            'active_tenant_id' => $user->tenant_id,
            'tenant_bypassed' => false,
        ]);
    }
}
