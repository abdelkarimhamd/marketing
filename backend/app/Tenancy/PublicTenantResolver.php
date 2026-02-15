<?php

namespace App\Tenancy;

use App\Models\ApiKey;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Support\DomainHost;
use Illuminate\Http\Request;

class PublicTenantResolver
{
    /**
     * Resolve tenant from API key, explicit headers, or domain.
     *
     * @return array{tenant: Tenant, source: string}|null
     */
    public function resolve(Request $request): ?array
    {
        $candidates = [];

        if ($apiKeyTenant = $this->resolveByApiKey($request)) {
            $candidates['api_key'] = $apiKeyTenant;
        }

        if ($headerTenant = $this->resolveByHeader($request)) {
            $candidates['header'] = $headerTenant;
        }

        if ($domainTenant = $this->resolveByDomain($request)) {
            $candidates['domain'] = $domainTenant;
        }

        if ($candidates === []) {
            return null;
        }

        /** @var Tenant $resolvedTenant */
        $resolvedTenant = reset($candidates);

        foreach ($candidates as $source => $candidate) {
            if ($candidate->id !== $resolvedTenant->id) {
                abort(
                    409,
                    "Conflicting tenant identifiers were provided (mismatch on source: {$source})."
                );
            }
        }

        return [
            'tenant' => $resolvedTenant,
            'source' => (string) array_key_first($candidates),
        ];
    }

    /**
     * Resolve tenant from API key.
     */
    private function resolveByApiKey(Request $request): ?Tenant
    {
        $plainTextKey = $this->extractApiKey($request);

        if ($plainTextKey === null) {
            return null;
        }

        $apiKey = ApiKey::findActiveByPlainText($plainTextKey);

        if ($apiKey === null) {
            abort(401, 'Invalid or expired API key.');
        }

        if (is_array($apiKey->abilities)
            && $apiKey->abilities !== []
            && ! in_array('public:leads:write', $apiKey->abilities, true)) {
            abort(403, 'API key does not have permission to submit public leads.');
        }

        return Tenant::query()
            ->whereKey($apiKey->tenant_id)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Resolve tenant from explicit tenant headers.
     */
    private function resolveByHeader(Request $request): ?Tenant
    {
        $tenantId = $request->header('X-Tenant-ID');

        if (is_string($tenantId) && is_numeric($tenantId) && (int) $tenantId > 0) {
            return Tenant::query()
                ->whereKey((int) $tenantId)
                ->where('is_active', true)
                ->first();
        }

        $tenantSlug = $request->header('X-Tenant-Slug');

        if (! is_string($tenantSlug) || trim($tenantSlug) === '') {
            return null;
        }

        return Tenant::query()
            ->where('slug', trim($tenantSlug))
            ->where('is_active', true)
            ->first();
    }

    /**
     * Resolve tenant from request domain context.
     */
    private function resolveByDomain(Request $request): ?Tenant
    {
        foreach ($this->domainCandidates($request) as $domain) {
            $tenantDomain = TenantDomain::query()
                ->withoutTenancy()
                ->where('host', $domain)
                ->where('verification_status', TenantDomain::VERIFICATION_VERIFIED)
                ->whereHas('tenant', fn ($query) => $query->where('is_active', true))
                ->with('tenant')
                ->first();

            if ($tenantDomain?->tenant !== null) {
                return $tenantDomain->tenant;
            }

            $tenant = Tenant::query()
                ->where('domain', $domain)
                ->where('is_active', true)
                ->first();

            if ($tenant !== null) {
                return $tenant;
            }

            $tenant = Tenant::query()
                ->where('is_active', true)
                ->whereJsonContains('settings->domains', $domain)
                ->first();

            if ($tenant !== null) {
                return $tenant;
            }
        }

        return null;
    }

    /**
     * Extract API key value from headers/body/query.
     */
    private function extractApiKey(Request $request): ?string
    {
        $value = $request->header('X-Api-Key');

        if (! is_string($value) || trim($value) === '') {
            $authorization = (string) $request->header('Authorization', '');

            if (str_starts_with($authorization, 'ApiKey ')) {
                $value = substr($authorization, strlen('ApiKey '));
            }
        }

        if (! is_string($value) || trim($value) === '') {
            $value = $request->input('api_key');
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * Build candidate domains from request metadata.
     *
     * @return list<string>
     */
    private function domainCandidates(Request $request): array
    {
        $candidates = [];

        $explicitDomain = $this->normalizeDomain((string) $request->header('X-Tenant-Domain', ''));
        if ($explicitDomain !== null) {
            $candidates[] = $explicitDomain;
        }

        $originDomain = $this->normalizeDomain((string) parse_url((string) $request->header('Origin', ''), PHP_URL_HOST));
        if ($originDomain !== null) {
            $candidates[] = $originDomain;
        }

        $refererDomain = $this->normalizeDomain((string) parse_url((string) $request->header('Referer', ''), PHP_URL_HOST));
        if ($refererDomain !== null) {
            $candidates[] = $refererDomain;
        }

        $requestHost = $this->normalizeDomain($request->getHost());
        if ($requestHost !== null) {
            $candidates[] = $requestHost;
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Normalize domain value and filter unusable local hosts.
     */
    private function normalizeDomain(string $domain): ?string
    {
        $domain = DomainHost::normalize($domain);

        if ($domain === null || DomainHost::isLocalHost($domain)) {
            return null;
        }

        return $domain;
    }
}
