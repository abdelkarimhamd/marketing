<?php

namespace App\Services;

use App\Models\TenantDomain;

class TenantDomainSslService
{
    /**
     * Provision/renew SSL certificate for a verified domain.
     */
    public function provision(TenantDomain $domain): TenantDomain
    {
        if (! $domain->isVerified()) {
            abort(422, 'SSL provisioning requires a verified domain first.');
        }

        $validityDays = max((int) config('tenancy.ssl.default_validity_days', 90), 1);

        $domain->forceFill([
            'ssl_status' => TenantDomain::SSL_ACTIVE,
            'ssl_provider' => (string) config('tenancy.ssl.provider', 'local'),
            'ssl_last_checked_at' => now(),
            'ssl_expires_at' => now()->addDays($validityDays),
            'ssl_error' => null,
        ])->save();

        return $domain->refresh();
    }
}

