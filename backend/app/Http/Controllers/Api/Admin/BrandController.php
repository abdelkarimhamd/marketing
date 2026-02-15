<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BrandController extends Controller
{
    /**
     * List brands for active tenant context.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'settings.view', requireTenantContext: false);
        $tenant = $this->resolveTenant($request);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Brand::query()
            ->withoutTenancy()
            ->where('tenant_id', (int) $tenant->id)
            ->withCount(['templates', 'campaigns']);

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);

            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('landing_domain', 'like', "%{$search}%")
                    ->orWhere('email_from_address', 'like', "%{$search}%")
                    ->orWhere('sms_sender_id', 'like', "%{$search}%")
                    ->orWhere('whatsapp_phone_number_id', 'like', "%{$search}%");
            });
        }

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        $rows = $query
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->paginate((int) ($filters['per_page'] ?? 30))
            ->withQueryString();

        return response()->json($rows);
    }

    /**
     * Create one brand profile under tenant.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'settings.update', requireTenantContext: false);
        $tenant = $this->resolveTenant($request);
        $payload = $this->validatePayload($request, (int) $tenant->id);
        $normalized = $this->normalizePayload($payload);

        $brand = Brand::query()->withoutTenancy()->create([
            'tenant_id' => (int) $tenant->id,
            ...$normalized,
        ]);

        return response()->json([
            'message' => 'Brand created successfully.',
            'brand' => $brand->loadCount(['templates', 'campaigns']),
        ], 201);
    }

    /**
     * Show one brand profile.
     */
    public function show(Request $request, Brand $brand): JsonResponse
    {
        $this->authorizePermission($request, 'settings.view', requireTenantContext: false);
        $tenant = $this->resolveTenant($request);
        $this->ensureTenantScope($tenant, $brand);

        return response()->json([
            'brand' => $brand->loadCount(['templates', 'campaigns'])->load([
                'templates:id,tenant_id,brand_id,name,slug,channel,is_active',
                'campaigns:id,tenant_id,brand_id,name,slug,status,campaign_type,channel',
            ]),
        ]);
    }

    /**
     * Update one brand profile.
     */
    public function update(Request $request, Brand $brand): JsonResponse
    {
        $this->authorizePermission($request, 'settings.update', requireTenantContext: false);
        $tenant = $this->resolveTenant($request);
        $this->ensureTenantScope($tenant, $brand);

        $payload = $this->validatePayload($request, (int) $tenant->id, true, $brand);
        $normalized = $this->normalizePayload($payload, true, $brand);

        $brand->forceFill($normalized)->save();

        return response()->json([
            'message' => 'Brand updated successfully.',
            'brand' => $brand->refresh()->loadCount(['templates', 'campaigns']),
        ]);
    }

    /**
     * Delete one brand profile.
     */
    public function destroy(Request $request, Brand $brand): JsonResponse
    {
        $this->authorizePermission($request, 'settings.update', requireTenantContext: false);
        $tenant = $this->resolveTenant($request);
        $this->ensureTenantScope($tenant, $brand);

        $brand->delete();

        return response()->json([
            'message' => 'Brand deleted successfully.',
        ]);
    }

    /**
     * Validate inbound brand payload.
     *
     * @return array<string, mixed>
     */
    private function validatePayload(
        Request $request,
        int $tenantId,
        bool $isUpdate = false,
        ?Brand $brand = null
    ): array {
        $slugRule = Rule::unique('brands', 'slug')
            ->where(fn ($query) => $query->where('tenant_id', $tenantId));
        $landingDomainRule = Rule::unique('brands', 'landing_domain')
            ->where(fn ($query) => $query->where('tenant_id', $tenantId));

        if ($brand !== null) {
            $slugRule->ignore($brand->id);
            $landingDomainRule->ignore($brand->id);
        }

        $rules = [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', $slugRule],
            'is_active' => ['sometimes', 'boolean'],
            'email_from_address' => ['sometimes', 'nullable', 'email', 'max:255'],
            'email_from_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email_reply_to' => ['sometimes', 'nullable', 'email', 'max:255'],
            'sms_sender_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'whatsapp_phone_number_id' => ['sometimes', 'nullable', 'string', 'max:120'],
            'landing_domain' => ['sometimes', 'nullable', 'string', 'max:255', $landingDomainRule],
            'landing_page' => ['sometimes', 'nullable', 'array'],
            'branding' => ['sometimes', 'nullable', 'array'],
            'signatures' => ['sometimes', 'nullable', 'array'],
            'signatures.email_html' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'signatures.email_text' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'signatures.sms' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'signatures.whatsapp' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'settings' => ['sometimes', 'nullable', 'array'],
        ];

        if (! $isUpdate) {
            $rules['name'][] = 'required';
        }

        return $request->validate($rules);
    }

    /**
     * Normalize brand payload for persistence.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload, bool $isUpdate = false, ?Brand $existing = null): array
    {
        $normalized = [];

        if (! $isUpdate || array_key_exists('name', $payload)) {
            $name = trim((string) ($payload['name'] ?? $existing?->name ?? ''));

            if ($name === '') {
                abort(422, 'Brand name is required.');
            }

            $normalized['name'] = $name;
        }

        if (! $isUpdate || array_key_exists('slug', $payload) || array_key_exists('name', $payload)) {
            $slugSource = array_key_exists('slug', $payload)
                ? trim((string) ($payload['slug'] ?? ''))
                : trim((string) ($normalized['name'] ?? $existing?->name ?? ''));
            $slug = Str::slug($slugSource);

            if ($slug === '') {
                abort(422, 'Brand slug cannot be empty.');
            }

            $normalized['slug'] = $slug;
        }

        if (array_key_exists('is_active', $payload)) {
            $normalized['is_active'] = (bool) $payload['is_active'];
        } elseif (! $isUpdate) {
            $normalized['is_active'] = true;
        }

        foreach ([
            'email_from_address',
            'email_from_name',
            'email_reply_to',
            'sms_sender_id',
            'whatsapp_phone_number_id',
            'landing_domain',
        ] as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $value = is_string($payload[$key]) ? trim((string) $payload[$key]) : '';
            $normalized[$key] = $value !== '' ? $value : null;
        }

        foreach (['landing_page', 'branding', 'settings'] as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $normalized[$key] = is_array($payload[$key]) ? $payload[$key] : [];
        }

        if (array_key_exists('signatures', $payload)) {
            $signatures = is_array($payload['signatures']) ? $payload['signatures'] : [];
            $normalized['signatures'] = [];

            foreach (['email_html', 'email_text', 'sms', 'whatsapp'] as $key) {
                $value = is_string($signatures[$key] ?? null) ? trim((string) $signatures[$key]) : '';

                if ($value !== '') {
                    $normalized['signatures'][$key] = $value;
                }
            }
        }

        return $normalized;
    }

    /**
     * Resolve active tenant context.
     */
    private function resolveTenant(Request $request): Tenant
    {
        $tenantId = $this->resolveTenantIdForPermission($request, $request->user());

        if ($tenantId !== null) {
            $tenant = Tenant::query()->whereKey($tenantId)->first();

            if ($tenant !== null) {
                return $tenant;
            }
        }

        abort(422, 'Tenant context is required.');
    }

    /**
     * Ensure brand belongs to active tenant.
     */
    private function ensureTenantScope(Tenant $tenant, Brand $brand): void
    {
        if ((int) $brand->tenant_id !== (int) $tenant->id) {
            abort(403, 'Brand does not belong to active tenant.');
        }
    }
}
