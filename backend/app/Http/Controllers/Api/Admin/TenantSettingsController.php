<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AssignmentRule;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantSettingsController extends Controller
{
    /**
     * Return tenant-scoped settings payload for the admin UI.
     */
    public function show(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $tenant = $this->resolveTenantForRequest($request, true);

        $settings = is_array($tenant->settings) ? $tenant->settings : [];

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'domain' => $tenant->domain,
                'is_active' => $tenant->is_active,
            ],
            'settings' => [
                'providers' => is_array($settings['providers'] ?? null) ? $settings['providers'] : [
                    'email' => config('messaging.providers.email', 'mock'),
                    'sms' => config('messaging.providers.sms', 'mock'),
                    'whatsapp' => config('messaging.providers.whatsapp', 'mock'),
                ],
                'domains' => is_array($settings['domains'] ?? null) ? $settings['domains'] : [],
                'slack' => is_array($settings['slack'] ?? null) ? $settings['slack'] : [],
                'rules' => AssignmentRule::query()
                    ->where('tenant_id', $tenant->id)
                    ->orderBy('priority')
                    ->get(),
            ],
        ]);
    }

    /**
     * Update tenant-scoped settings payload.
     */
    public function update(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $tenant = $this->resolveTenantForRequest($request, false);

        $payload = $request->validate([
            'domain' => ['nullable', 'string', 'max:255'],
            'providers' => ['nullable', 'array'],
            'providers.email' => ['nullable', 'string', 'max:120'],
            'providers.sms' => ['nullable', 'string', 'max:120'],
            'providers.whatsapp' => ['nullable', 'string', 'max:120'],
            'domains' => ['nullable', 'array'],
            'domains.*' => ['string', 'max:255'],
            'slack' => ['nullable', 'array'],
            'slack.webhook_url' => ['nullable', 'string', 'max:1000'],
            'slack.channel' => ['nullable', 'string', 'max:255'],
            'slack.enabled' => ['nullable', 'boolean'],
        ]);

        $settings = is_array($tenant->settings) ? $tenant->settings : [];

        if (array_key_exists('providers', $payload) && is_array($payload['providers'])) {
            $settings['providers'] = array_merge(
                is_array($settings['providers'] ?? null) ? $settings['providers'] : [],
                $payload['providers']
            );
        }

        if (array_key_exists('domains', $payload) && is_array($payload['domains'])) {
            $settings['domains'] = array_values(array_unique(array_map(
                static fn (string $domain): string => trim($domain),
                array_filter($payload['domains'], static fn ($value): bool => trim((string) $value) !== '')
            )));
        }

        if (array_key_exists('slack', $payload) && is_array($payload['slack'])) {
            $settings['slack'] = array_merge(
                is_array($settings['slack'] ?? null) ? $settings['slack'] : [],
                $payload['slack']
            );
        }

        $tenant->forceFill([
            'domain' => array_key_exists('domain', $payload) ? $payload['domain'] : $tenant->domain,
            'settings' => $settings,
        ])->save();

        return response()->json([
            'message' => 'Tenant settings updated.',
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'domain' => $tenant->domain,
                'is_active' => $tenant->is_active,
            ],
            'settings' => $settings,
        ]);
    }

    /**
     * Resolve tenant for read/write operations.
     */
    private function resolveTenantForRequest(Request $request, bool $allowBypass): Tenant
    {
        $tenantId = $request->attributes->get('tenant_id');

        if (is_int($tenantId) && $tenantId > 0) {
            $tenant = Tenant::query()->whereKey($tenantId)->first();

            if ($tenant !== null) {
                return $tenant;
            }
        }

        $requestedTenantId = $request->query('tenant_id', $request->input('tenant_id'));

        if (is_numeric($requestedTenantId) && (int) $requestedTenantId > 0) {
            $tenant = Tenant::query()->whereKey((int) $requestedTenantId)->first();

            if ($tenant !== null) {
                return $tenant;
            }
        }

        if ($allowBypass && (bool) $request->attributes->get('tenant_bypassed', false)) {
            abort(422, 'Select tenant_id to load settings while in bypass mode.');
        }

        abort(422, 'Tenant context is required for settings.');
    }

    /**
     * Ensure caller has admin permission.
     */
    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        if (! $user || ! $user->isAdmin()) {
            abort(403, 'Admin permissions are required.');
        }
    }
}
