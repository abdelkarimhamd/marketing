<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Tenant;
use App\Support\DomainHost;
use Illuminate\Http\Request;

class BrandResolutionService
{
    /**
     * Resolve active brand for tenant from request identifiers and domains.
     *
     * Priority: explicit id -> explicit slug -> domain match -> single active brand fallback.
     *
     * @param array<string, mixed> $payload
     */
    public function resolveForTenant(Tenant $tenant, Request $request, array $payload = []): ?Brand
    {
        $explicitId = $this->extractBrandId($payload, $request);
        $explicitSlug = $this->extractBrandSlug($payload, $request);

        if ($explicitId !== null) {
            return Brand::query()
                ->withoutTenancy()
                ->where('tenant_id', (int) $tenant->id)
                ->where('is_active', true)
                ->whereKey($explicitId)
                ->first();
        }

        if ($explicitSlug !== null) {
            return Brand::query()
                ->withoutTenancy()
                ->where('tenant_id', (int) $tenant->id)
                ->where('is_active', true)
                ->where('slug', $explicitSlug)
                ->first();
        }

        foreach ($this->domainCandidates($request) as $domain) {
            $brand = Brand::query()
                ->withoutTenancy()
                ->where('tenant_id', (int) $tenant->id)
                ->where('is_active', true)
                ->where('landing_domain', $domain)
                ->first();

            if ($brand instanceof Brand) {
                return $brand;
            }
        }

        $brands = Brand::query()
            ->withoutTenancy()
            ->where('tenant_id', (int) $tenant->id)
            ->where('is_active', true)
            ->limit(2)
            ->get();

        if ($brands->count() === 1) {
            return $brands->first();
        }

        return null;
    }

    /**
     * Merge tenant branding with brand-specific override.
     *
     * @return array<string, mixed>
     */
    public function mergedBranding(Tenant $tenant, ?Brand $brand): array
    {
        $tenantBranding = is_array($tenant->branding) ? $tenant->branding : [];
        $brandBranding = is_array($brand?->branding) ? $brand->branding : [];

        return array_replace_recursive($tenantBranding, $brandBranding);
    }

    /**
     * Resolve one tenant brand by explicit identifier.
     */
    public function resolveByIdOrSlug(Tenant $tenant, ?int $brandId, ?string $brandSlug): ?Brand
    {
        if (is_int($brandId) && $brandId > 0) {
            return Brand::query()
                ->withoutTenancy()
                ->where('tenant_id', (int) $tenant->id)
                ->where('is_active', true)
                ->whereKey($brandId)
                ->first();
        }

        if (is_string($brandSlug) && trim($brandSlug) !== '') {
            return Brand::query()
                ->withoutTenancy()
                ->where('tenant_id', (int) $tenant->id)
                ->where('is_active', true)
                ->where('slug', trim(mb_strtolower($brandSlug)))
                ->first();
        }

        return null;
    }

    /**
     * Parse brand id from payload/header/query.
     *
     * @param array<string, mixed> $payload
     */
    private function extractBrandId(array $payload, Request $request): ?int
    {
        $candidates = [
            $payload['brand_id'] ?? null,
            $request->header('X-Brand-ID'),
            $request->query('brand_id'),
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                return (int) $candidate;
            }
        }

        return null;
    }

    /**
     * Parse brand slug from payload/header/query.
     *
     * @param array<string, mixed> $payload
     */
    private function extractBrandSlug(array $payload, Request $request): ?string
    {
        $candidates = [
            $payload['brand_slug'] ?? null,
            $request->header('X-Brand-Slug'),
            $request->query('brand_slug'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $slug = trim(mb_strtolower($candidate));

            if ($slug !== '') {
                return $slug;
            }
        }

        return null;
    }

    /**
     * Build domain candidates from request metadata.
     *
     * @return list<string>
     */
    private function domainCandidates(Request $request): array
    {
        $candidates = [];

        $explicitDomain = $this->normalizeDomain((string) $request->header('X-Brand-Domain', ''));
        if ($explicitDomain !== null) {
            $candidates[] = $explicitDomain;
        }

        $originDomain = $this->normalizeDomain(
            (string) parse_url((string) $request->header('Origin', ''), PHP_URL_HOST)
        );
        if ($originDomain !== null) {
            $candidates[] = $originDomain;
        }

        $refererDomain = $this->normalizeDomain(
            (string) parse_url((string) $request->header('Referer', ''), PHP_URL_HOST)
        );
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
     * Normalize and filter unusable local domains.
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
