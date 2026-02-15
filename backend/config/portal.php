<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Customer Portal Defaults
    |--------------------------------------------------------------------------
    */
    'enabled' => true,

    'headline' => 'Talk to our team',
    'subtitle' => 'Request a quote, book a demo, upload documents, and track your request status.',

    'features' => [
        'request_quote' => true,
        'book_demo' => true,
        'upload_docs' => true,
        'track_status' => true,
    ],

    'source_prefix' => 'portal',
    'default_status' => 'new',
    'auto_assign' => true,
    'default_tags' => ['portal'],

    /*
    |--------------------------------------------------------------------------
    | Tracking Tokens
    |--------------------------------------------------------------------------
    */
    'tracking_token_ttl_days' => 180,

    /*
    |--------------------------------------------------------------------------
    | Booking Defaults
    |--------------------------------------------------------------------------
    */
    'booking' => [
        'default_timezone' => 'UTC',
        'allowed_channels' => ['video', 'phone', 'in_person'],
        'default_duration_minutes' => 30,
        'deal_stage_on_booking' => 'demo_booked',
        'default_link' => null,
    ],
];
