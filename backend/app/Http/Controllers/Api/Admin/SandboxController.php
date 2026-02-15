<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TenantSandbox;
use App\Services\TenantSandboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SandboxController extends Controller
{
    /**
     * List sandboxes for current tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'settings.view', requireTenantContext: false);
        $tenantId = $this->tenantId($request);

        $rows = TenantSandbox::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->with('sandboxTenant:id,name,slug')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'sandboxes' => $rows,
        ]);
    }

    /**
     * Create/update sandbox clone.
     */
    public function store(Request $request, TenantSandboxService $sandboxService): JsonResponse
    {
        $this->authorizePermission($request, 'settings.update', requireTenantContext: false);
        $tenant = $request->user()?->tenant;
        $tenantId = $this->tenantId($request);

        if ($tenant === null || (int) $tenant->id !== $tenantId) {
            $tenant = \App\Models\Tenant::query()->whereKey($tenantId)->firstOrFail();
        }

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'anonymized' => ['nullable', 'boolean'],
        ]);

        $sandbox = $sandboxService->createSandbox(
            tenant: $tenant,
            name: $payload['name'],
            anonymized: (bool) ($payload['anonymized'] ?? true),
        );

        return response()->json([
            'message' => 'Sandbox clone created successfully.',
            'sandbox' => $sandbox->load('sandboxTenant:id,name,slug'),
        ], 201);
    }

    /**
     * Promote sandbox configuration to production tenant.
     */
    public function promote(Request $request, TenantSandbox $tenantSandbox, TenantSandboxService $sandboxService): JsonResponse
    {
        $this->authorizePermission($request, 'settings.update', requireTenantContext: false);
        $tenantId = $this->tenantId($request);

        if ((int) $tenantSandbox->tenant_id !== $tenantId) {
            abort(404, 'Sandbox not found in tenant scope.');
        }

        $sandbox = $sandboxService->promoteConfigurations($tenantSandbox);

        return response()->json([
            'message' => 'Sandbox configuration promoted successfully.',
            'sandbox' => $sandbox->load('sandboxTenant:id,name,slug'),
        ]);
    }

    private function tenantId(Request $request): int
    {
        $tenantId = $request->attributes->get('tenant_id');

        if (is_int($tenantId) && $tenantId > 0) {
            return $tenantId;
        }

        $requested = $request->query('tenant_id', $request->input('tenant_id'));

        if (is_numeric($requested) && (int) $requested > 0) {
            return (int) $requested;
        }

        abort(422, 'Tenant context is required.');
    }
}

