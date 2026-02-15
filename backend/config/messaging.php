<?php

return [
    'queues' => [
        // Keep campaign dispatch simple in development: all send jobs on default queue.
        // Set MESSAGING_USE_CHANNEL_QUEUES=true to split by channel queues.
        'use_channel_queues' => (bool) env('MESSAGING_USE_CHANNEL_QUEUES', false),
        'default' => env('MESSAGING_QUEUE_DEFAULT', 'default'),
        'email' => env('MESSAGING_QUEUE_EMAIL', 'send-email'),
        'sms' => env('MESSAGING_QUEUE_SMS', 'send-sms'),
        'whatsapp' => env('MESSAGING_QUEUE_WHATSAPP', 'send-whatsapp'),
    ],

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

    'reply_domain' => env('MAIL_REPLY_DOMAIN', parse_url((string) env('APP_URL', ''), PHP_URL_HOST)),

    'rate_limits' => [
        'email' => (int) env('QUEUE_RATE_LIMIT_EMAIL_PER_MINUTE', 240),
        'sms' => (int) env('QUEUE_RATE_LIMIT_SMS_PER_MINUTE', 180),
        'whatsapp' => (int) env('QUEUE_RATE_LIMIT_WHATSAPP_PER_MINUTE', 120),
    ],
];
