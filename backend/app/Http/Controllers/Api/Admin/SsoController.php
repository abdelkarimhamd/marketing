<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScimAccessToken;
use App\Models\Tenant;
use App\Models\TenantSsoConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SsoController extends Controller
{
    /**
     * List tenant SSO config and SCIM tokens.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'settings.view', requireTenantContext: false);
        $tenant = $this->tenant($request);

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'sso_required' => (bool) $tenant->sso_required,
            ],
            'configs' => TenantSsoConfig::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenant->id)
                ->orderBy('provider')
                ->get(),
            'scim_tokens' => ScimAccessToken::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenant->id)
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    /**
     * Create/update SSO configuration row.
     */
    public function saveConfig(Request $request, ?TenantSsoConfig $tenantSsoConfig = null): JsonResponse
    {
        $this->authorizePermission($request, 'settings.update', requireTenantContext: false);
        $tenant = $this->tenant($request);

        if ($tenantSsoConfig !== null && (int) $tenantSsoConfig->tenant_id !== (int) $tenant->id) {
            abort(404, 'SSO config not found in tenant scope.');
        }

        $payload = $request->validate([
            'provider' => ['required', Rule::in(['oidc', 'saml'])],
            'enabled' => ['nullable', 'boolean'],
            'enforce_sso' => ['nullable', 'boolean'],
            'settings' => ['nullable', 'array'],
            'settings.issuer' => ['nullable', 'string', 'max:255'],
            'settings.client_id' => ['nullable', 'string', 'max:255'],
            'settings.client_secret' => ['nullable', 'string', 'max:500'],
            'settings.authorization_endpoint' => ['nullable', 'string', 'max:500'],
            'settings.token_endpoint' => ['nullable', 'string', 'max:500'],
            'settings.jwks_uri' => ['nullable', 'string', 'max:500'],
            'sso_required' => ['nullable', 'boolean'],
        ]);

        $config = ($tenantSsoConfig ?? new TenantSsoConfig())->fill([
            'tenant_id' => $tenant->id,
            'provider' => $payload['provider'],
            'enabled' => $payload['enabled'] ?? true,
            'enforce_sso' => $payload['enforce_sso'] ?? false,
            'settings' => $payload['settings'] ?? [],
        ]);
        $config->save();

        if (array_key_exists('sso_required', $payload)) {
            $tenant->forceFill([
                'sso_required' => (bool) $payload['sso_required'],
            ])->save();
        }

        return response()->json([
            'message' => 'SSO configuration saved.',
            'config' => $config,
            'tenant' => [
                'id' => $tenant->id,
                'sso_required' => (bool) $tenant->fresh()->sso_required,
            ],
        ], $tenantSsoConfig ? 200 : 201);
    }

    /**
     * Create one SCIM token and return plaintext once.
     */
    public function createScimToken(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'settings.update', requireTenantContext: false);
        $tenant = $this->tenant($request);

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $plainText = 'scim_'.Str::random(42);

        $token = ScimAccessToken::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'name' => $payload['name'],
            'token_hash' => hash('sha256', $plainText),
            'expires_at' => $payload['expires_at'] ?? null,
        ]);

        return response()->json([
            'message' => 'SCIM token created.',
            'token' => $token,
            'plain_text_token' => $plainText,
        ], 201);
    }

    /**
     * Revoke SCIM token.
     */
    public function revokeScimToken(Request $request, ScimAccessToken $scimAccessToken): JsonResponse
    {
        $this->authorizePermission($request, 'settings.update', requireTenantContext: false);
        $tenant = $this->tenant($request);

        if ((int) $scimAccessToken->tenant_id !== (int) $tenant->id) {
            abort(404, 'SCIM token not found in tenant scope.');
        }

        $scimAccessToken->forceFill([
            'revoked_at' => now(),
        ])->save();

        return response()->json([
            'message' => 'SCIM token revoked.',
        ]);
    }

    private function tenant(Request $request): Tenant
    {
        $tenantId = $request->attributes->get('tenant_id');

        if (! is_int($tenantId) || $tenantId <= 0) {
            $requested = $request->query('tenant_id', $request->input('tenant_id'));

            if (is_numeric($requested) && (int) $requested > 0) {
                $tenantId = (int) $requested;
            }
        }

        if (! is_int($tenantId) || $tenantId <= 0) {
            abort(422, 'Tenant context is required.');
        }

        return Tenant::query()->whereKey($tenantId)->firstOrFail();
    }
}

