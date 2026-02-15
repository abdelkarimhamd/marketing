<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantSsoConfig;
use App\Models\User;
use App\Support\PermissionMatrix;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Authenticate user and create a Sanctum token.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $email = Str::lower(trim((string) $credentials['email']));

        /** @var User|null $user */
        $user = User::query()
            ->withoutTenancy()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->isSuperAdmin() && $user->tenant_id !== null) {
            $tenant = Tenant::query()->whereKey($user->tenant_id)->first();

            if ($tenant?->sso_required) {
                $enforcedSso = TenantSsoConfig::query()
                    ->withoutTenancy()
                    ->where('tenant_id', $tenant->id)
                    ->where('enabled', true)
                    ->where('enforce_sso', true)
                    ->exists();

                if ($enforcedSso) {
                    abort(403, 'Password login is disabled for this tenant. Use SSO.');
                }
            }
        }

        $token = $user->createToken(
            $credentials['device_name'] ?? 'api-token'
        )->plainTextToken;

        $authPayload = $this->buildAuthPayload($request, $user);

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            ...$authPayload,
        ]);
    }

    /**
     * Return currently authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->buildAuthPayload($request, $user));
    }

    /**
     * Revoke the current Sanctum token.
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $token = $user->currentAccessToken();

        if ($token !== null) {
            $token->delete();
        } else {
            $user->tokens()->delete();
        }

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Build frontend auth payload with tenant-aware permissions.
     *
     * @return array<string, mixed>
     */
    private function buildAuthPayload(Request $request, User $user): array
    {
        $tenantId = $this->resolveTenantIdForPermission($request, $user);
        $permissionMatrix = $user->effectivePermissionMatrix($tenantId);
        $flat = app(PermissionMatrix::class)->flattenMatrix($permissionMatrix);
        $permissions = array_keys(array_filter($flat, static fn (bool $allowed): bool => $allowed));

        return [
            'user' => $user->load('tenant'),
            'permission_matrix' => $permissionMatrix,
            'permissions' => Arr::sort($permissions),
            'tenant_context_id' => $tenantId,
        ];
    }
}
