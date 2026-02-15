<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Bot Automation Defaults
    |--------------------------------------------------------------------------
    |
    | Tenant settings may override these values under: settings.bot
    |
    */
    'enabled' => (bool) env('BOT_AUTOMATION_ENABLED', true),

    'channels' => [
        'whatsapp' => (bool) env('BOT_WHATSAPP_ENABLED', true),
        'website_chat' => (bool) env('BOT_WEBSITE_CHAT_ENABLED', true),
    ],

    'welcome_message' => env(
        'BOT_WELCOME_MESSAGE',
        'Hi, I can help with quick answers and qualifying your request.'
    ),

    'default_reply' => env(
        'BOT_DEFAULT_REPLY',
        'Thanks for your message. I can ask a few questions and route you to the right team.'
    ),

    'handoff_reply' => env(
        'BOT_HANDOFF_REPLY',
        'Understood. I am connecting you to a human agent now.'
    ),

    'completion_reply' => env(
        'BOT_COMPLETION_REPLY',
        'Thanks. Your request is qualified and routed. A specialist will follow up shortly.'
    ),

    'handoff_keywords' => [
        'agent',
        'human',
        'support',
        'help',
        'representative',
    ],

    'qualification' => [
        'enabled' => true,
        'auto_qualify' => true,
        'questions' => [
            [
                'key' => 'full_name',
                'question' => 'Can I get your full name?',
                'field' => 'full_name',
                'required' => true,
            ],
            [
                'key' => 'company',
                'question' => 'What is your company name?',
                'field' => 'company',
                'required' => true,
            ],
            [
                'key' => 'interest',
                'question' => 'What service are you interested in?',
                'field' => 'interest',
                'required' => true,
            ],
            [
                'key' => 'city',
                'question' => 'Which city are you based in?',
                'field' => 'city',
                'required' => false,
            ],
            [
                'key' => 'email',
                'question' => 'What email should we use for follow-up?',
                'field' => 'email',
                'required' => false,
            ],
        ],
    ],

    'faq' => [
        [
            'question' => 'Pricing plans',
            'keywords' => ['price', 'pricing', 'cost'],
            'answer' => 'Pricing depends on channels and volume. Share your use case and we will send a tailored plan.',
        ],
        [
            'question' => 'Setup timeline',
            'keywords' => ['setup', 'onboarding', 'timeline', 'how long'],
            'answer' => 'Typical onboarding takes a few days depending on domain verification and integrations.',
        ],
    ],

    'appointment' => [
        'enabled' => true,
        'keywords' => ['book', 'demo', 'appointment', 'meeting'],
        'booking_url' => env('BOT_BOOKING_URL', null),
        'reply' => env('BOT_BOOKING_REPLY', 'You can book a slot here: {{booking_url}}'),
    ],
];
