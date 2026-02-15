<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\TenantDomainManager;
use App\Support\DomainHost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TenantDomainController extends Controller
{
    /**
     * List domains for active tenant context.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'settings.view', requireTenantContext: false);
        $tenant = $this->resolveTenant($request);

        $domains = TenantDomain::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('is_primary')
            ->orderBy('kind')
            ->orderBy('host')
            ->get();

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
            'domains' => $domains,
        ]);
    }

    /**
     * Register one custom domain.
     */
    public function store(Request $request, TenantDomainManager $manager): JsonResponse
    {
        $this->authorizePermission($request, 'settings.update', requireTenantContext: false);
        $tenant = $this->resolveTenant($request);

        $payload = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in([TenantDomain::KIND_ADMIN, TenantDomain::KIND_LANDING])],
            'is_primary' => ['nullable', 'boolean'],
            'cname_target' => ['nullable', 'string', 'max:255'],
        ]);

        $domain = $manager->register(
            tenant: $tenant,
            host: (string) $payload['host'],
            kind: (string) $payload['kind'],
            isPrimary: (bool) ($payload['is_primary'] ?? false),
            cnameTarget: isset($payload['cname_target']) ? (string) $payload['cname_target'] : null,
        );

        return response()->json([
            'message' => 'Domain registered. Configure DNS and verify ownership.',
            'domain' => $domain,
            'dns' => [
                'type' => 'CNAME',
                'host' => $domain->host,
                'target' => DomainHost::normalize((string) $domain->cname_target),
                'verification_token' => $domain->verification_token,
            ],
        ], 201);
    }

    /**
     * Trigger CNAME validation for one domain.
     */
    public function verify(
        Request $request,
        TenantDomain $tenantDomain,
        TenantDomainManager $manager
    ): JsonResponse {
        $this->authorizePermission($request, 'settings.update', requireTenantContext: false);
        $tenant = $this->resolveTenant($request);
        $this->ensureTenantScope($tenant, $tenantDomain);

        $domain = $manager->verify($tenantDomain);

        return response()->json([
            'message' => $domain->isVerified()
                ? 'Domain verified successfully.'
                : 'Domain verification failed. Check CNAME and retry.',
            'domain' => $domain,
        ]);
    }

    /**
     * Provision SSL cert immediately for one verified domain.
     */
    public function provisionSsl(
        Request $request,
        TenantDomain $tenantDomain,
        TenantDomainManager $manager
    ): JsonResponse {
        $this->authorizePermission($request, 'settings.update', requireTenantContext: false);
        $tenant = $this->resolveTenant($request);
        $this->ensureTenantScope($tenant, $tenantDomain);

        $domain = $manager->provisionSsl($tenantDomain);

        return response()->json([
            'message' => 'SSL provisioning completed.',
            'domain' => $domain,
        ]);
    }

    /**
     * Mark selected domain as primary for its kind.
     */
    public function setPrimary(
        Request $request,
        TenantDomain $tenantDomain,
        TenantDomainManager $manager
    ): JsonResponse {
        $this->authorizePermission($request, 'settings.update', requireTenantContext: false);
        $tenant = $this->resolveTenant($request);
        $this->ensureTenantScope($tenant, $tenantDomain);

        $domain = $manager->setPrimary($tenantDomain);

        return response()->json([
            'message' => 'Primary domain updated.',
            'domain' => $domain,
        ]);
    }

    /**
     * Delete custom domain.
     */
    public function destroy(
        Request $request,
        TenantDomain $tenantDomain,
        TenantDomainManager $manager
    ): JsonResponse {
        $this->authorizePermission($request, 'settings.update', requireTenantContext: false);
        $tenant = $this->resolveTenant($request);
        $this->ensureTenantScope($tenant, $tenantDomain);

        $manager->delete($tenantDomain);

        return response()->json([
            'message' => 'Domain deleted.',
        ]);
    }

    /**
     * Resolve tenant by active context/query/user tenant.
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
     * Ensure requested domain belongs to active tenant.
     */
    private function ensureTenantScope(Tenant $tenant, TenantDomain $domain): void
    {
        if ((int) $domain->tenant_id !== (int) $tenant->id) {
            abort(403, 'Domain does not belong to active tenant.');
        }
    }
}
