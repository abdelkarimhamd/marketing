<?php

namespace App\Services;

use App\Jobs\ProvisionTenantDomainSslJob;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Support\DomainHost;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantDomainManager
{
    public function __construct(
        private readonly TenantDomainDnsService $dnsService,
        private readonly TenantDomainSslService $sslService,
    ) {
    }

    /**
     * Register one tenant domain and return provisioning instructions.
     */
    public function register(
        Tenant $tenant,
        string $host,
        string $kind = TenantDomain::KIND_LANDING,
        bool $isPrimary = false,
        ?string $cnameTarget = null,
    ): TenantDomain {
        $normalizedHost = $this->normalizeRequiredHost($host);
        $normalizedKind = $this->normalizeKind($kind);
        $normalizedCnameTarget = $this->normalizeCnameTarget(
            $cnameTarget !== null && trim($cnameTarget) !== ''
                ? $cnameTarget
                : $this->cnameTargetForKind($normalizedKind)
        );

        if ($this->isLocalOnlyHost($normalizedCnameTarget) && ! $this->shouldBypassDnsVerification($normalizedHost)) {
            abort(
                422,
                'CNAME target is local-only. Set TENANCY_CNAME_TARGET to a public host or provide "cname_target".'
            );
        }

        $domain = TenantDomain::query()
            ->withoutTenancy()
            ->create([
                'tenant_id' => $tenant->id,
                'host' => $normalizedHost,
                'kind' => $normalizedKind,
                'is_primary' => $isPrimary,
                'cname_target' => $normalizedCnameTarget,
                'verification_token' => Str::random(40),
                'verification_status' => TenantDomain::VERIFICATION_PENDING,
                'verified_at' => null,
                'verification_error' => null,
                'ssl_status' => TenantDomain::SSL_PENDING,
                'ssl_provider' => null,
                'ssl_expires_at' => null,
                'ssl_last_checked_at' => null,
                'ssl_error' => null,
                'metadata' => [],
            ]);

        if ($isPrimary) {
            $this->setPrimary($domain);
        }

        return $domain->refresh();
    }

    /**
     * Validate CNAME and trigger SSL automation when configured.
     */
    public function verify(TenantDomain $domain): TenantDomain
    {
        $expected = DomainHost::normalize((string) $domain->cname_target);

        if ($expected === null) {
            $domain->forceFill([
                'verification_status' => TenantDomain::VERIFICATION_FAILED,
                'verification_error' => 'Missing CNAME target configuration.',
                'verified_at' => null,
            ])->save();

            return $domain->refresh();
        }

        $cnameTargets = $this->shouldBypassDnsVerification((string) $domain->host)
            ? [$expected]
            : $this->dnsService->lookupCname((string) $domain->host);
        $verified = in_array($expected, $cnameTargets, true);
        $verificationError = null;

        if (! $verified) {
            $verificationError = count($cnameTargets) === 0
                ? sprintf(
                    'No CNAME record found for "%s". Add CNAME to "%s".',
                    (string) $domain->host,
                    $expected
                )
                : sprintf(
                    'CNAME mismatch. Expected "%s", got "%s".',
                    $expected,
                    implode(', ', $cnameTargets)
                );
        }

        $domain->forceFill([
            'verification_status' => $verified
                ? TenantDomain::VERIFICATION_VERIFIED
                : TenantDomain::VERIFICATION_FAILED,
            'verification_error' => $verificationError,
            'verified_at' => $verified ? now() : null,
            'ssl_last_checked_at' => now(),
        ])->save();

        if ($verified && $domain->ssl_status !== TenantDomain::SSL_ACTIVE) {
            $domain->forceFill([
                'ssl_status' => TenantDomain::SSL_PENDING,
                'ssl_error' => null,
            ])->save();
        }

        if ($verified && (bool) config('tenancy.ssl.auto_provision', true)) {
            ProvisionTenantDomainSslJob::dispatch($domain->id);
        }

        return $domain->refresh();
    }

    /**
     * Allow bypassing DNS verification for local development domains.
     */
    private function shouldBypassDnsVerification(string $host): bool
    {
        if (! app()->environment(['local', 'testing'])) {
            return false;
        }

        if (! (bool) config('tenancy.verification.allow_local_bypass', true)) {
            return false;
        }

        $normalizedHost = DomainHost::normalize($host);

        if ($normalizedHost === null) {
            return false;
        }

        $suffixes = config('tenancy.verification.local_bypass_suffixes', ['.localhost', '.test', '.local']);

        if (! is_array($suffixes) || $suffixes === []) {
            return false;
        }

        foreach ($suffixes as $suffix) {
            if (! is_string($suffix)) {
                continue;
            }

            $trimmed = strtolower(trim($suffix));

            if ($trimmed === '') {
                continue;
            }

            if (str_starts_with($trimmed, '.')) {
                if (str_ends_with($normalizedHost, $trimmed)) {
                    return true;
                }

                continue;
            }

            if ($normalizedHost === $trimmed) {
                return true;
            }
        }

        return false;
    }

    /**
     * Provision SSL certificate for one domain.
     */
    public function provisionSsl(TenantDomain $domain): TenantDomain
    {
        if (! $domain->isVerified()) {
            abort(422, 'Domain must be verified before provisioning SSL.');
        }

        $domain->forceFill([
            'ssl_status' => TenantDomain::SSL_PROVISIONING,
            'ssl_error' => null,
            'ssl_last_checked_at' => now(),
        ])->save();

        try {
            return $this->sslService->provision($domain->refresh());
        } catch (\Throwable $throwable) {
            $domain->forceFill([
                'ssl_status' => TenantDomain::SSL_FAILED,
                'ssl_error' => $throwable->getMessage(),
                'ssl_last_checked_at' => now(),
            ])->save();

            return $domain->refresh();
        }
    }

    /**
     * Mark one domain as primary for its kind.
     */
    public function setPrimary(TenantDomain $domain): TenantDomain
    {
        DB::transaction(function () use ($domain): void {
            TenantDomain::query()
                ->withoutTenancy()
                ->where('tenant_id', $domain->tenant_id)
                ->where('kind', $domain->kind)
                ->update(['is_primary' => false]);

            TenantDomain::query()
                ->withoutTenancy()
                ->whereKey($domain->id)
                ->update(['is_primary' => true]);

            $tenant = Tenant::query()->whereKey($domain->tenant_id)->first();

            if ($tenant !== null && $domain->kind === TenantDomain::KIND_LANDING) {
                $tenant->forceFill(['domain' => $domain->host])->save();
            }
        });

        return $domain->refresh();
    }

    /**
     * Delete domain and keep tenant primary fallback consistent.
     */
    public function delete(TenantDomain $domain): void
    {
        DB::transaction(function () use ($domain): void {
            $tenantId = (int) $domain->tenant_id;
            $kind = (string) $domain->kind;
            $wasPrimary = (bool) $domain->is_primary;
            $host = (string) $domain->host;

            $domain->delete();

            if (! $wasPrimary) {
                return;
            }

            $fallbackPrimary = TenantDomain::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->where('kind', $kind)
                ->orderByDesc('verification_status')
                ->orderByDesc('id')
                ->first();

            if ($fallbackPrimary !== null) {
                $this->setPrimary($fallbackPrimary);

                return;
            }

            if ($kind === TenantDomain::KIND_LANDING) {
                $tenant = Tenant::query()->whereKey($tenantId)->first();

                if ($tenant !== null && (string) $tenant->domain === $host) {
                    $tenant->forceFill(['domain' => null])->save();
                }
            }
        });
    }

    /**
     * Return required CNAME target for one domain kind.
     */
    public function cnameTargetForKind(string $kind): string
    {
        $targets = config('tenancy.cname_targets', []);

        if (is_array($targets) && isset($targets[$kind]) && is_string($targets[$kind])) {
            $configured = DomainHost::normalize((string) $targets[$kind]);

            if ($configured !== null && ! $this->isLocalOnlyHost($configured)) {
                return $configured;
            }
        }

        $global = DomainHost::normalize((string) config('tenancy.cname_target', 'tenant.marketion.local'));

        if ($global !== null && ! $this->isLocalOnlyHost($global)) {
            return $global;
        }

        $appUrlHost = DomainHost::normalize((string) parse_url((string) config('app.url', ''), PHP_URL_HOST));

        if ($appUrlHost !== null && ! $this->isLocalOnlyHost($appUrlHost)) {
            return $appUrlHost;
        }

        return (string) ($global ?? 'tenant.marketion.local');
    }

    /**
     * Normalize and validate host value.
     */
    private function normalizeRequiredHost(string $host): string
    {
        $normalized = DomainHost::normalize($host);

        if ($normalized === null || DomainHost::isLocalHost($normalized)) {
            abort(422, 'Invalid domain host provided.');
        }

        return $normalized;
    }

    /**
     * Normalize and validate domain kind.
     */
    private function normalizeKind(string $kind): string
    {
        $kind = strtolower(trim($kind));

        if (! in_array($kind, [TenantDomain::KIND_ADMIN, TenantDomain::KIND_LANDING], true)) {
            abort(422, 'Invalid domain kind. Use admin or landing.');
        }

        return $kind;
    }

    /**
     * Normalize and validate CNAME target value.
     */
    private function normalizeCnameTarget(string $cnameTarget): string
    {
        $normalized = DomainHost::normalize($cnameTarget);

        if ($normalized === null) {
            abort(422, 'Invalid CNAME target. Provide a valid host, for example app.yourdomain.com.');
        }

        return $normalized;
    }

    /**
     * Determine whether host is local-only and unsuitable for public DNS providers.
     */
    private function isLocalOnlyHost(string $host): bool
    {
        if (DomainHost::isLocalHost($host) || ! str_contains($host, '.')) {
            return true;
        }

        $suffixes = config('tenancy.verification.local_bypass_suffixes', ['.localhost', '.test', '.local']);

        if (! is_array($suffixes)) {
            return false;
        }

        foreach ($suffixes as $suffix) {
            if (! is_string($suffix)) {
                continue;
            }

            $trimmed = strtolower(trim($suffix));

            if ($trimmed === '') {
                continue;
            }

            if (str_starts_with($trimmed, '.')) {
                if (str_ends_with($host, $trimmed)) {
                    return true;
                }

                continue;
            }

            if ($host === $trimmed) {
                return true;
            }
        }

        return false;
    }
}
