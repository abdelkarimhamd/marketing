<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantRole;
use App\Models\User;
use App\Services\TenantRoleTemplateService;
use App\Support\PermissionMatrix;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    /**
     * List tenant roles.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'roles.view');
        $tenantId = $this->resolveTenantIdStrict($request);

        $roles = TenantRole::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->withCount('users')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $roles,
        ]);
    }

    /**
     * Return available role templates.
     */
    public function templates(Request $request, TenantRoleTemplateService $templates): JsonResponse
    {
        $this->authorizePermission($request, 'roles.view');

        return response()->json([
            'templates' => $templates->templates(),
        ]);
    }

    /**
     * Return users assignable to tenant roles.
     */
    public function assignableUsers(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'roles.assign');
        $tenantId = $this->resolveTenantIdStrict($request);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));
        $limit = (int) ($filters['limit'] ?? 100);

        $usersQuery = User::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('is_super_admin', false)
            ->orderBy('name');

        if ($search !== '') {
            $usersQuery->where(function ($builder) use ($search): void {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'data' => $usersQuery->limit($limit)->get(['id', 'name', 'email']),
        ]);
    }

    /**
     * Show one role.
     */
    public function show(Request $request, TenantRole $tenantRole): JsonResponse
    {
        $this->authorizePermission($request, 'roles.view');
        $this->ensureRoleTenantScope($request, $tenantRole);

        return response()->json([
            'role' => $tenantRole->load([
                'users:id,name,email,tenant_id,role',
                'creator:id,name,email',
                'updater:id,name,email',
            ]),
        ]);
    }

    /**
     * Create custom role.
     */
    public function store(Request $request, PermissionMatrix $permissionMatrix): JsonResponse
    {
        $this->authorizePermission($request, 'roles.create');

        $tenantId = $this->resolveTenantIdStrict($request);
        $user = $request->user();

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('tenant_roles', 'slug')->where(
                    fn ($builder) => $builder->where('tenant_id', $tenantId)
                ),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'permissions' => ['required', 'array'],
        ]);

        $normalized = $permissionMatrix->normalizeMatrix($payload['permissions']);
        $this->guardOverPermission($request, $normalized, $tenantId, $permissionMatrix);

        $role = TenantRole::query()
            ->withoutTenancy()
            ->create([
                'tenant_id' => $tenantId,
                'name' => $payload['name'],
                'slug' => $payload['slug'] ?? Str::slug($payload['name']),
                'description' => $payload['description'] ?? null,
                'permissions' => $normalized,
                'is_system' => false,
                'created_by' => $user?->id,
                'updated_by' => $user?->id,
            ]);

        return response()->json([
            'message' => 'Role created successfully.',
            'role' => $role->loadCount('users'),
        ], 201);
    }

    /**
     * Update custom role.
     */
    public function update(Request $request, TenantRole $tenantRole, PermissionMatrix $permissionMatrix): JsonResponse
    {
        $this->authorizePermission($request, 'roles.update');
        $this->ensureRoleTenantScope($request, $tenantRole);

        if ($tenantRole->is_system) {
            abort(422, 'System role templates cannot be modified.');
        }

        $tenantId = (int) $tenantRole->tenant_id;
        $user = $request->user();

        $payload = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('tenant_roles', 'slug')->where(
                    fn ($builder) => $builder->where('tenant_id', $tenantId)
                )->ignore($tenantRole->id),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'permissions' => ['sometimes', 'array'],
        ]);

        $permissions = is_array($payload['permissions'] ?? null)
            ? $permissionMatrix->normalizeMatrix($payload['permissions'])
            : $tenantRole->permissions;

        if (is_array($payload['permissions'] ?? null)) {
            $this->guardOverPermission($request, $permissions, $tenantId, $permissionMatrix);
        }

        $tenantRole->forceFill([
            'name' => $payload['name'] ?? $tenantRole->name,
            'slug' => $payload['slug'] ?? $tenantRole->slug,
            'description' => array_key_exists('description', $payload)
                ? $payload['description']
                : $tenantRole->description,
            'permissions' => $permissions,
            'updated_by' => $user?->id,
        ])->save();

        return response()->json([
            'message' => 'Role updated successfully.',
            'role' => $tenantRole->refresh()->loadCount('users'),
        ]);
    }

    /**
     * Delete custom role.
     */
    public function destroy(Request $request, TenantRole $tenantRole): JsonResponse
    {
        $this->authorizePermission($request, 'roles.delete');
        $this->ensureRoleTenantScope($request, $tenantRole);

        if ($tenantRole->is_system) {
            abort(422, 'System role templates cannot be deleted.');
        }

        $tenantRole->users()->detach();
        $tenantRole->delete();

        return response()->json([
            'message' => 'Role deleted successfully.',
        ]);
    }

    /**
     * Assign one role to user.
     */
    public function assign(
        Request $request,
        TenantRole $tenantRole,
        PermissionMatrix $permissionMatrix
    ): JsonResponse {
        $this->authorizePermission($request, 'roles.assign');
        $this->ensureRoleTenantScope($request, $tenantRole);

        $tenantId = (int) $tenantRole->tenant_id;
        $payload = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $user = User::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->whereKey((int) $payload['user_id'])
            ->first();

        if ($user === null || $user->isSuperAdmin()) {
            abort(422, 'User is not assignable for this tenant role.');
        }

        $this->guardOverPermission($request, (array) $tenantRole->permissions, $tenantId, $permissionMatrix);

        $tenantRole->users()->syncWithoutDetaching([
            $user->id => ['tenant_id' => $tenantId],
        ]);

        return response()->json([
            'message' => 'Role assigned successfully.',
        ]);
    }

    /**
     * Unassign one role from user.
     */
    public function unassign(Request $request, TenantRole $tenantRole): JsonResponse
    {
        $this->authorizePermission($request, 'roles.assign');
        $this->ensureRoleTenantScope($request, $tenantRole);

        $payload = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $tenantRole->users()->detach((int) $payload['user_id']);

        return response()->json([
            'message' => 'Role unassigned successfully.',
        ]);
    }

    /**
     * Resolve tenant id or fail.
     */
    private function resolveTenantIdStrict(Request $request): int
    {
        $tenantId = $this->resolveTenantIdForPermission($request, $request->user());

        if ($tenantId !== null && Tenant::query()->whereKey($tenantId)->exists()) {
            return $tenantId;
        }

        abort(422, 'Tenant context is required.');
    }

    /**
     * Ensure route model role belongs to active tenant.
     */
    private function ensureRoleTenantScope(Request $request, TenantRole $tenantRole): void
    {
        $tenantId = $this->resolveTenantIdStrict($request);

        if ((int) $tenantRole->tenant_id !== $tenantId) {
            abort(403, 'Role does not belong to active tenant context.');
        }
    }

    /**
     * Prevent non-super users from granting permissions they don't have.
     *
     * @param array<string, mixed> $requestedPermissions
     */
    private function guardOverPermission(
        Request $request,
        array $requestedPermissions,
        int $tenantId,
        PermissionMatrix $permissionMatrix
    ): void {
        $user = $request->user();

        if (! $user instanceof User || $user->isSuperAdmin()) {
            return;
        }

        $actorMatrix = $user->effectivePermissionMatrix($tenantId);
        $requestedFlat = $permissionMatrix->flattenMatrix($requestedPermissions);

        foreach ($requestedFlat as $permission => $allowed) {
            if (! $allowed) {
                continue;
            }

            if (! $permissionMatrix->allows($actorMatrix, $permission)) {
                abort(422, "Cannot grant permission '{$permission}' beyond your own access level.");
            }
        }
    }
}
