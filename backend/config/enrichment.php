<?php

return [
    'email' => [
        // When true, emails without MX records are treated as invalid.
        'require_mx' => (bool) env('LEAD_ENRICHMENT_EMAIL_REQUIRE_MX', false),
        // When true, disposable email domains are rejected with 422.
        'reject_disposable' => (bool) env('LEAD_ENRICHMENT_REJECT_DISPOSABLE', false),
        // Enable DNS MX lookup for public domains.
        'check_mx' => (bool) env('LEAD_ENRICHMENT_EMAIL_CHECK_MX', true),
        // Common disposable inbox domains.
        'disposable_domains' => [
            '10minutemail.com',
            'guerrillamail.com',
            'maildrop.cc',
            'mailinator.com',
            'tempmail.com',
            'temp-mail.org',
            'yopmail.com',
        ],
        // Public mailbox providers (skip company-name inference).
        'free_domains' => [
            'gmail.com',
            'hotmail.com',
            'icloud.com',
            'outlook.com',
            'proton.me',
            'protonmail.com',
            'yahoo.com',
            'yandex.com',
        ],
        // Domains that should not be DNS-validated in local/test-like environments.
        'local_suffixes' => ['.localhost', '.local', '.test'],
    ],

    'phone' => [
        // If true, phone must be valid when email is missing.
        'require_valid_without_email' => (bool) env('LEAD_ENRICHMENT_REQUIRE_VALID_PHONE_WITHOUT_EMAIL', true),
        // Minimal dial code map used for country inference.
        'dialing_country_map' => [
            '1' => 'US',
            '20' => 'EG',
            '44' => 'GB',
            '91' => 'IN',
            '966' => 'SA',
            '971' => 'AE',
            '973' => 'BH',
            '974' => 'QA',
            '965' => 'KW',
            '968' => 'OM',
        ],
        // Local-country normalization support (country -> dial code).
        'country_dialing_map' => [
            'US' => '1',
            'EG' => '20',
            'GB' => '44',
            'IN' => '91',
            'SA' => '966',
            'AE' => '971',
            'BH' => '973',
            'QA' => '974',
            'KW' => '965',
            'OM' => '968',
        ],
        // Very lightweight carrier prefix map (currently Saudi mobile ranges).
        'carrier_prefixes' => [
            'SA' => [
                '50' => 'stc',
                '53' => 'stc',
                '54' => 'mobily',
                '55' => 'zain',
                '56' => 'mobily',
                '57' => 'virgin',
                '58' => 'zain',
                '59' => 'zain',
            ],
        ],
    ],

    'company' => [
        'enable_domain_inference' => (bool) env('LEAD_ENRICHMENT_COMPANY_DOMAIN_INFERENCE', true),
        // Use explicit overrides for known domains when city/country must be exact.
        'domain_overrides' => [
            'smartcedra.com' => [
                'name' => 'Smart Cedra',
                'city' => 'Riyadh',
                'country_code' => 'SA',
            ],
            'smartcedra.online' => [
                'name' => 'Smart Cedra',
                'city' => 'Riyadh',
                'country_code' => 'SA',
            ],
        ],
        'country_by_tld' => [
            'ae' => 'AE',
            'bh' => 'BH',
            'eg' => 'EG',
            'in' => 'IN',
            'kw' => 'KW',
            'om' => 'OM',
            'qa' => 'QA',
            'sa' => 'SA',
            'uk' => 'GB',
            'us' => 'US',
        ],
        // Lightweight public suffix exceptions to improve SLD extraction.
        'second_level_suffixes' => [
            'co.uk',
            'com.au',
            'com.br',
            'com.eg',
            'com.sa',
            'co.in',
            'co.za',
        ],
    ],

    'score' => [
        'valid_email_bonus' => 15,
        'disposable_email_penalty' => 25,
        'missing_mx_penalty' => 5,
        'valid_phone_bonus' => 10,
        'invalid_phone_penalty' => 12,
        'company_enriched_bonus' => 8,
        'geo_enriched_bonus' => 5,
        'min' => 0,
        'max' => 100,
    ],
];
