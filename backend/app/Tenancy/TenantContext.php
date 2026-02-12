<?php

namespace App\Tenancy;

class TenantContext
{
    private ?int $tenantId = null;

    private bool $bypassed = false;

    /**
     * Set active tenant context.
     */
    public function setTenant(int $tenantId): void
    {
        $this->tenantId = $tenantId;
        $this->bypassed = false;
    }

    /**
     * Enable super-admin bypass context.
     */
    public function bypass(): void
    {
        $this->tenantId = null;
        $this->bypassed = true;
    }

    /**
     * Clear active tenant context without bypass mode.
     */
    public function clear(): void
    {
        $this->tenantId = null;
        $this->bypassed = false;
    }

    /**
     * Get currently active tenant id.
     */
    public function tenantId(): ?int
    {
        return $this->tenantId;
    }

    /**
     * Determine if an active tenant is set.
     */
    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    /**
     * Determine if tenancy filtering is bypassed.
     */
    public function isBypassed(): bool
    {
        return $this->bypassed;
    }
}
