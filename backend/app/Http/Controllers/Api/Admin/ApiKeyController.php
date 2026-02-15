<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApiKeyController extends Controller
{
    /**
     * List API keys for active tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'api_keys.view');
        $tenantId = $this->resolveTenantId($request);

        $keys = ApiKey::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->with('creator:id,name,email')
            ->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 25))
            ->withQueryString();

        return response()->json($keys);
    }

    /**
     * Create an API key and return plaintext once.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'api_keys.create');
        $tenantId = $this->resolveTenantId($request);

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => ['string', 'max:120'],
            'expires_at' => ['nullable', 'date'],
            'settings' => ['nullable', 'array'],
        ]);

        $plainText = 'mk_'.Str::random(48);
        $prefix = substr($plainText, 0, 16);

        $apiKey = ApiKey::query()->withoutTenancy()->create([
            'tenant_id' => $tenantId,
            'created_by' => optional($request->user())->id,
            'name' => $payload['name'],
            'prefix' => $prefix,
            'key_hash' => hash('sha256', $plainText),
            'secret' => $plainText,
            'abilities' => $payload['abilities'] ?? [],
            'settings' => $payload['settings'] ?? [],
            'expires_at' => $payload['expires_at'] ?? null,
            'revoked_at' => null,
        ]);

        return response()->json([
            'message' => 'API key created.',
            'api_key' => $apiKey->fresh(['creator:id,name,email']),
            'plain_text_key' => $plainText,
        ], 201);
    }

    /**
     * Revoke API key access.
     */
    public function revoke(Request $request, ApiKey $apiKey): JsonResponse
    {
        $this->authorizePermission($request, 'api_keys.update');
        $this->ensureKeyBelongsToTenant($request, $apiKey);

        $apiKey->forceFill([
            'revoked_at' => now(),
        ])->save();

        return response()->json([
            'message' => 'API key revoked.',
            'api_key' => $apiKey->refresh(),
        ]);
    }

    /**
     * Delete API key row.
     */
    public function destroy(Request $request, ApiKey $apiKey): JsonResponse
    {
        $this->authorizePermission($request, 'api_keys.delete');
        $this->ensureKeyBelongsToTenant($request, $apiKey);

        $apiKey->delete();

        return response()->json([
            'message' => 'API key deleted.',
        ]);
    }

    /**
     * Resolve tenant id from context/header/payload.
     */
    private function resolveTenantId(Request $request): int
    {
        $tenantId = $request->attributes->get('tenant_id');

        if (is_int($tenantId) && $tenantId > 0) {
            return $tenantId;
        }

        $inputTenantId = $request->query('tenant_id', $request->input('tenant_id'));

        if (is_numeric($inputTenantId) && (int) $inputTenantId > 0) {
            $resolvedTenantId = (int) $inputTenantId;

            if (! Tenant::query()->whereKey($resolvedTenantId)->exists()) {
                abort(404, 'Tenant not found.');
            }

            return $resolvedTenantId;
        }

        abort(422, 'Tenant context is required for API key operations.');
    }

    /**
     * Ensure key belongs to active tenant context.
     */
    private function ensureKeyBelongsToTenant(Request $request, ApiKey $apiKey): void
    {
        $tenantId = $this->resolveTenantId($request);

        if ((int) $apiKey->tenant_id !== $tenantId) {
            abort(403, 'API key does not belong to the active tenant.');
        }
    }

}
