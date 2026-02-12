<?php

return [
    'providers' => [
        'email' => env('EMAIL_PROVIDER', 'mock'),
        'sms' => env('SMS_PROVIDER', 'mock'),
        'whatsapp' => env('WHATSAPP_PROVIDER', 'mock'),
    ],

    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_FROM'),
        'status_callback_url' => env('TWILIO_STATUS_CALLBACK_URL'),
    ],

    'meta_whatsapp' => [
        'token' => env('META_WHATSAPP_TOKEN'),
        'phone_number_id' => env('META_WHATSAPP_PHONE_NUMBER_ID'),
        'verify_token' => env('META_WHATSAPP_VERIFY_TOKEN'),
        'version' => env('META_WHATSAPP_VERSION', 'v20.0'),
        'default_language' => env('META_WHATSAPP_DEFAULT_LANGUAGE', 'en_US'),
    ],

    'tracking' => [
        'open_pixel_enabled' => (bool) env('EMAIL_OPEN_TRACKING_ENABLED', true),
        'click_tracking_enabled' => (bool) env('EMAIL_CLICK_TRACKING_ENABLED', true),
        'token_ttl_days' => (int) env('MESSAGE_TRACKING_TOKEN_TTL_DAYS', 60),
    ],
];
