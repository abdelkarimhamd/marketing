<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Tenant Encryption Provider
    |--------------------------------------------------------------------------
    |
    | "local" keeps a wrapped data-encryption-key per tenant in the database.
    | Future providers (for example KMS-backed) can reuse the same metadata
    | model by writing provider + key_reference only.
    |
    */
    'provider' => env('TENANT_ENCRYPTION_PROVIDER', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Future KMS Reference
    |--------------------------------------------------------------------------
    */
    'kms_key_id' => env('TENANT_ENCRYPTION_KMS_KEY_ID'),

    /*
    |--------------------------------------------------------------------------
    | Cipher Settings
    |--------------------------------------------------------------------------
    */
    'cipher' => env('TENANT_ENCRYPTION_CIPHER', 'AES-256-CBC'),
    'key_bytes' => (int) env('TENANT_ENCRYPTION_KEY_BYTES', 32),
    'token_prefix' => 'tenantenc:v1',

    /*
    |--------------------------------------------------------------------------
    | Data Residency Regions
    |--------------------------------------------------------------------------
    |
    | "global" keeps default behavior until regional storage is introduced.
    |
    */
    'residency_regions' => [
        'global',
        'us',
        'eu',
        'me',
        'apac',
    ],

    /*
    |--------------------------------------------------------------------------
    | Settings Paths To Re-encrypt During Rotation
    |--------------------------------------------------------------------------
    */
    'settings_reencrypt_paths' => [
        'email_delivery.smtp_password_encrypted',
    ],
];

