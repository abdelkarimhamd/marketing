<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TenantController extends Controller
{
    /**
     * Return tenants available to the current user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            $tenants = Tenant::query()
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'slug',
                    'public_key',
                    'domain',
                    'timezone',
                    'locale',
                    'currency',
                    'data_residency_region',
                    'data_residency_locked',
                    'is_active',
                    'created_at',
                    'updated_at',
                ]);

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
            'data' => $tenant ? [$tenant->only([
                'id',
                'name',
                'slug',
                'public_key',
                'domain',
                'timezone',
                'locale',
                'currency',
                'data_residency_region',
                'data_residency_locked',
                'is_active',
                'created_at',
                'updated_at',
            ])] : [],
            'active_tenant_id' => $user->tenant_id,
            'tenant_bypassed' => false,
        ]);
    }

    /**
     * Create a new tenant (super admin only).
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'settings.update', requireTenantContext: false);

        if (! $request->user()?->isSuperAdmin()) {
            abort(403, 'Only super admin can create tenants.');
        }

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'domain' => ['nullable', 'string', 'max:255', Rule::unique('tenants', 'domain')],
            'timezone' => ['nullable', 'string', 'max:64'],
            'locale' => ['nullable', 'string', 'max:12'],
            'currency' => ['nullable', 'string', 'max:8'],
            'data_residency_region' => ['nullable', Rule::in($this->residencyRegions())],
            'data_residency_locked' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $slugSource = trim((string) ($payload['slug'] ?? $payload['name']));
        $slug = Str::slug($slugSource);

        if ($slug === '') {
            abort(422, 'Tenant slug cannot be empty.');
        }

        $slug = $this->generateUniqueSlug($slug);

        $tenant = Tenant::query()->create([
            'name' => trim((string) $payload['name']),
            'slug' => $slug,
            'public_key' => 'trk_'.Str::lower(Str::random(40)),
            'domain' => isset($payload['domain']) ? trim((string) $payload['domain']) : null,
            'timezone' => trim((string) ($payload['timezone'] ?? 'UTC')) ?: 'UTC',
            'locale' => trim((string) ($payload['locale'] ?? 'en')) ?: 'en',
            'currency' => trim((string) ($payload['currency'] ?? 'USD')) ?: 'USD',
            'data_residency_region' => $this->normalizeResidencyRegion($payload['data_residency_region'] ?? 'global'),
            'data_residency_locked' => (bool) ($payload['data_residency_locked'] ?? false),
            'sso_required' => false,
            'is_active' => (bool) ($payload['is_active'] ?? true),
            'settings' => [],
            'branding' => [],
        ]);

        return response()->json([
            'message' => 'Tenant created successfully.',
            'tenant' => $tenant->only([
                'id',
                'name',
                'slug',
                'public_key',
                'domain',
                'timezone',
                'locale',
                'currency',
                'data_residency_region',
                'data_residency_locked',
                'is_active',
                'created_at',
                'updated_at',
            ]),
        ], 201);
    }

    /**
     * Generate unique slug for tenants.
     */
    private function generateUniqueSlug(string $baseSlug): string
    {
        $slug = $baseSlug;
        $suffix = 2;

        while (Tenant::query()->where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /**
     * @return list<string>
     */
    private function residencyRegions(): array
    {
        $regions = config('tenant_encryption.residency_regions', []);

        if (! is_array($regions)) {
            return ['global'];
        }

        $normalized = array_values(array_filter(array_map(
            static fn (mixed $region): string => is_string($region) ? trim(mb_strtolower($region)) : '',
            $regions
        )));

        return $normalized !== [] ? array_values(array_unique($normalized)) : ['global'];
    }

    private function normalizeResidencyRegion(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim(mb_strtolower($value));

        return $normalized !== '' ? $normalized : null;
    }
}
