<?php

return [
    'telephony' => [
        'enabled' => (bool) env('TELEPHONY_ENABLED', false),
        'provider' => (string) env('TELEPHONY_PROVIDER', 'null'),
    ],

    'ai' => [
        'enabled' => (bool) env('AI_ENABLED', false),
        'provider' => (string) env('AI_PROVIDER', 'null'),
        'max_requests_per_minute' => (int) env('AI_MAX_REQUESTS_PER_MINUTE', 30),
    ],

    'personalization' => [
        'enabled' => (bool) env('PERSONALIZATION_ENABLED', true),
        'allowed_actions' => [
            'replace_text',
            'set_href',
            'set_attr',
            'hide',
            'show',
        ],
        'selector_allowlist' => array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', (string) env('PERSONALIZATION_SELECTOR_ALLOWLIST', '.hero,[data-personalize],#main,.cta'))
        ))),
    ],

    'experiments' => [
        'enabled' => (bool) env('EXPERIMENTS_ENABLED', true),
    ],

    'marketplace' => [
        'enabled' => (bool) env('MARKETPLACE_ENABLED', true),
    ],
];
