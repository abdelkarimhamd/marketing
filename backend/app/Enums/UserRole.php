<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case TenantAdmin = 'tenant_admin';
    case Sales = 'sales';

    /**
     * Determine if this role has admin capabilities.
     */
    public function isAdmin(): bool
    {
        return $this === self::SuperAdmin || $this === self::TenantAdmin;
    }
}
