<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Provider Cost Rates (per outbound message)
    |--------------------------------------------------------------------------
    */
    'provider_costs' => [
        'email' => [
            'mock' => 0.0000,
            'smtp' => 0.0015,
        ],
        'sms' => [
            'mock' => 0.0000,
            'twilio' => 0.0300,
        ],
        'whatsapp' => [
            'mock' => 0.0000,
            'meta' => 0.0450,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Operational Overhead (per outbound message)
    |--------------------------------------------------------------------------
    */
    'overhead_per_message' => [
        'email' => 0.0008,
        'sms' => 0.0015,
        'whatsapp' => 0.0025,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Revenue Rates (estimated per outbound message)
    |--------------------------------------------------------------------------
    |
    | Can be overridden per tenant in settings.cost_engine.revenue_per_message.
    |
    */
    'revenue_per_message' => [
        'email' => 0.0040,
        'sms' => 0.0500,
        'whatsapp' => 0.0700,
    ],

    /*
    |--------------------------------------------------------------------------
    | Margin Alert Defaults
    |--------------------------------------------------------------------------
    */
    'margin_alert_threshold_percent' => 15.00,
    'margin_alert_min_messages' => 10,
];

