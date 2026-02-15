<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeviceTokenController extends Controller
{
    /**
     * Register mobile push token.
     */
    public function register(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $user = $request->user();

        if ($user === null) {
            abort(401, 'Authentication is required.');
        }

        $payload = $request->validate([
            'platform' => ['required', Rule::in(['ios', 'android', 'web'])],
            'token' => ['required', 'string', 'max:255'],
            'meta' => ['nullable', 'array'],
        ]);

        $deviceToken = DeviceToken::query()
            ->withoutTenancy()
            ->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'token' => trim((string) $payload['token']),
                ],
                [
                    'user_id' => (int) $user->id,
                    'platform' => (string) $payload['platform'],
                    'meta' => is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
                    'last_seen_at' => now(),
                ],
            );

        return response()->json([
            'message' => 'Device token registered.',
            'device_token' => $deviceToken,
        ], 201);
    }

    /**
     * Unregister mobile push token.
     */
    public function unregister(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $user = $request->user();

        if ($user === null) {
            abort(401, 'Authentication is required.');
        }

        $payload = $request->validate([
            'token' => ['required', 'string', 'max:255'],
        ]);

        DeviceToken::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('user_id', (int) $user->id)
            ->where('token', trim((string) $payload['token']))
            ->delete();

        return response()->json([
            'message' => 'Device token unregistered.',
        ]);
    }

    private function tenantId(Request $request): int
    {
        $tenantId = $this->resolveTenantIdForPermission($request, $request->user());

        if ($tenantId === null || $tenantId <= 0) {
            abort(422, 'Tenant context is required.');
        }

        return $tenantId;
    }
}
