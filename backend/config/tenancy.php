<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Domain Verification
    |--------------------------------------------------------------------------
    |
    | Configure CNAME targets that tenant domains must point to.
    |
    */
    'cname_target' => env('TENANCY_CNAME_TARGET', 'tenant.marketion.local'),

    'cname_targets' => [
        'admin' => env('TENANCY_ADMIN_CNAME_TARGET', env('TENANCY_CNAME_TARGET', 'tenant.marketion.local')),
        'landing' => env('TENANCY_LANDING_CNAME_TARGET', env('TENANCY_CNAME_TARGET', 'tenant.marketion.local')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Verification Behavior
    |--------------------------------------------------------------------------
    |
    | Local development can bypass DNS checks for specific suffixes so teams
    | can validate domain flows without public DNS delegation.
    |
    */
    'verification' => [
        'allow_local_bypass' => (bool) env('TENANCY_VERIFICATION_ALLOW_LOCAL_BYPASS', true),
        'local_bypass_suffixes' => array_values(array_filter(array_map(
            static fn (string $value): string => strtolower(trim($value)),
            explode(',', (string) env('TENANCY_VERIFICATION_LOCAL_BYPASS_SUFFIXES', '.localhost,.test,.local'))
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | SSL Automation
    |--------------------------------------------------------------------------
    |
    | Basic SSL automation settings used by tenant domain provisioning.
    |
    */
    'ssl' => [
        'provider' => env('TENANCY_SSL_PROVIDER', 'local'),
        'auto_provision' => (bool) env('TENANCY_SSL_AUTO_PROVISION', true),
        'default_validity_days' => (int) env('TENANCY_SSL_DEFAULT_VALIDITY_DAYS', 90),
    ],
];
