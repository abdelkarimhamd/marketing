<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Starter Playbooks
    |--------------------------------------------------------------------------
    |
    | Default onboarding playbooks that can be imported per tenant.
    |
    */
    'starters' => [
        [
            'name' => 'Clinic Discovery Call',
            'slug' => 'clinic-discovery-call',
            'industry' => 'clinic',
            'stage' => 'new',
            'channel' => 'call',
            'scripts' => [
                'Confirm patient volume and top service lines before proposing packages.',
                'Qualify current booking process and no-show pain points.',
                'Set a clear next step with timeline and decision owner.',
            ],
            'objections' => [
                [
                    'objection' => 'We already have a receptionist workflow.',
                    'response' => 'Great. We complement that by automating reminders and follow-up so your team focuses on high-value conversations.',
                ],
                [
                    'objection' => 'Budget is tight this quarter.',
                    'response' => 'We can start with one clinic location and prove recovery from missed appointments before expanding.',
                ],
            ],
            'templates' => [
                [
                    'title' => 'Clinic Follow-up Email',
                    'channel' => 'email',
                    'content' => 'Hi {{first_name}}, thanks for your time today. Based on your current no-show challenge, we can help improve confirmations in 2 weeks. Are you available on {{next_meeting_date}} for a rollout plan?',
                ],
                [
                    'title' => 'Clinic Reminder SMS',
                    'channel' => 'sms',
                    'content' => 'Hi {{first_name}}, quick check-in from {{company}}. Should we send a short plan for reducing no-shows this month?',
                ],
            ],
        ],
        [
            'name' => 'Real Estate Lead Qualification',
            'slug' => 'real-estate-lead-qualification',
            'industry' => 'real_estate',
            'stage' => 'contacted',
            'channel' => 'whatsapp',
            'scripts' => [
                'Confirm budget, preferred area, and move-in timeline in first interaction.',
                'Prioritize leads with explicit financing readiness and visit intent.',
                'Offer two tailored property options instead of broad catalog dumps.',
            ],
            'objections' => [
                [
                    'objection' => 'I need to think first before site visits.',
                    'response' => 'Understood. I can share a short comparison of the top two options so your decision is faster and clearer.',
                ],
                [
                    'objection' => 'Prices look high compared to online listings.',
                    'response' => 'Some listings miss transfer fees and service charges. I can provide a full cost breakdown so you compare accurately.',
                ],
            ],
            'templates' => [
                [
                    'title' => 'Property Visit WhatsApp',
                    'channel' => 'whatsapp',
                    'content' => 'Hi {{first_name}}, based on your budget and area preference, I shortlisted 2 properties. Would you like a visit on {{visit_date}}?',
                ],
                [
                    'title' => 'Real Estate Re-engagement Email',
                    'channel' => 'email',
                    'content' => 'Hi {{first_name}}, sharing updated options in {{city}} that match your criteria. Reply with your preferred time for a quick review call.',
                ],
            ],
        ],
        [
            'name' => 'Restaurant Catering Pipeline',
            'slug' => 'restaurant-catering-pipeline',
            'industry' => 'restaurant',
            'stage' => 'proposal',
            'channel' => 'email',
            'scripts' => [
                'Ask event size, cuisine preference, and required service level early.',
                'Present package tiers clearly with minimum guest thresholds.',
                'Always include tasting and confirmation deadlines in proposals.',
            ],
            'objections' => [
                [
                    'objection' => 'Another vendor is cheaper.',
                    'response' => 'We can align package scope while keeping quality and delivery reliability. Let me share a side-by-side menu and service comparison.',
                ],
                [
                    'objection' => 'We are not ready to confirm dates yet.',
                    'response' => 'No problem. We can place a soft hold for 72 hours so you keep the slot while finalizing internally.',
                ],
            ],
            'templates' => [
                [
                    'title' => 'Catering Proposal Email',
                    'channel' => 'email',
                    'content' => 'Hi {{first_name}}, attached is your catering proposal with menu options for {{event_date}}. If approved by {{approval_deadline}}, we will secure kitchen and delivery slots.',
                ],
                [
                    'title' => 'Catering Follow-up SMS',
                    'channel' => 'sms',
                    'content' => 'Hi {{first_name}}, just checking if you need any edits to the catering proposal before we lock availability.',
                ],
            ],
        ],
    ],
];
