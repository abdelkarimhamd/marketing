<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ScimProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScimController extends Controller
{
    /**
     * List tenant users for SCIM sync.
     */
    public function index(Request $request, ScimProvisioningService $scimService): JsonResponse
    {
        [$tenant, $token] = $this->resolveTenantContext($request, $scimService);

        $rows = User::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->orderBy('id')
            ->get();

        $token->forceFill(['last_used_at' => now()])->save();

        return response()->json([
            'totalResults' => $rows->count(),
            'Resources' => $rows->map(fn (User $user): array => $this->resource($user))->values(),
        ]);
    }

    /**
     * Create user via SCIM.
     */
    public function store(Request $request, ScimProvisioningService $scimService): JsonResponse
    {
        [$tenant, $token] = $this->resolveTenantContext($request, $scimService);
        $user = $scimService->provisionUser($tenant, $request->all());
        $token->forceFill(['last_used_at' => now()])->save();

        return response()->json($this->resource($user), 201);
    }

    /**
     * Update user via SCIM payload.
     */
    public function update(string $id, Request $request, ScimProvisioningService $scimService): JsonResponse
    {
        [$tenant, $token] = $this->resolveTenantContext($request, $scimService);
        $user = User::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->whereKey((int) $id)
            ->first();

        if ($user === null) {
            return response()->json(['detail' => 'User not found.'], 404);
        }

        $payload = $request->all();
        $payload['userName'] = $payload['userName'] ?? $user->email;

        $updated = $scimService->provisionUser($tenant, $payload);
        $token->forceFill(['last_used_at' => now()])->save();

        return response()->json($this->resource($updated));
    }

    /**
     * Disable user via SCIM delete.
     */
    public function destroy(string $id, Request $request, ScimProvisioningService $scimService): JsonResponse
    {
        [$tenant, $token] = $this->resolveTenantContext($request, $scimService);
        $user = User::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->whereKey((int) $id)
            ->first();

        if ($user !== null) {
            $scimService->disableUser($user);
        }

        $token->forceFill(['last_used_at' => now()])->save();

        return response()->json([], 204);
    }

    /**
     * @return array{Tenant, \App\Models\ScimAccessToken}
     */
    private function resolveTenantContext(Request $request, ScimProvisioningService $scimService): array
    {
        $authorization = (string) $request->header('Authorization', '');
        $tokenString = '';

        if (str_starts_with($authorization, 'Bearer ')) {
            $tokenString = trim(substr($authorization, 7));
        }

        $token = $scimService->token($tokenString);

        if ($token === null) {
            abort(401, 'Invalid SCIM token.');
        }

        $tenant = Tenant::query()->whereKey($token->tenant_id)->first();

        if ($tenant === null) {
            abort(401, 'Invalid SCIM tenant context.');
        }

        return [$tenant, $token];
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(User $user): array
    {
        return [
            'id' => (string) $user->id,
            'userName' => $user->email,
            'displayName' => $user->name,
            'active' => true,
            'emails' => [
                ['value' => $user->email, 'primary' => true],
            ],
            'meta' => [
                'resourceType' => 'User',
                'created' => optional($user->created_at)->toIso8601String(),
                'lastModified' => optional($user->updated_at)->toIso8601String(),
            ],
        ];
    }
}

