<?php

namespace App\Jobs;

use App\Models\TenantDomain;
use App\Services\TenantDomainManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProvisionTenantDomainSslJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $tenantDomainId,
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(TenantDomainManager $manager): void
    {
        $domain = TenantDomain::query()
            ->withoutTenancy()
            ->whereKey($this->tenantDomainId)
            ->first();

        if ($domain === null) {
            return;
        }

        if (! $domain->isVerified()) {
            return;
        }

        $manager->provisionSsl($domain);
    }
}

