<?php

namespace Tests\Feature;

use App\Models\AssignmentRule;
use App\Models\Lead;
use App\Models\Message;
use App\Models\RealtimeEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotAutomationHandoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_chat_widget_returns_tenant_bot_configuration(): void
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'chat-widget',
            'settings' => [
                'bot' => [
                    'enabled' => true,
                    'channels' => [
                        'website_chat' => true,
                        'whatsapp' => false,
                    ],
                    'welcome_message' => 'Welcome to Smart Cedra chat.',
                    'qualification' => [
                        'enabled' => true,
                        'questions' => [
                            [
                                'key' => 'name',
                                'question' => 'What is your name?',
                                'field' => 'full_name',
                                'required' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->withHeader('X-Tenant-Slug', $tenant->slug)
            ->getJson('/api/public/chat/widget')
            ->assertOk()
            ->assertJsonPath('tenant.id', $tenant->id)
            ->assertJsonPath('chat.enabled', true)
            ->assertJsonPath('chat.welcome_message', 'Welcome to Smart Cedra chat.')
            ->assertJsonPath('chat.qualification.enabled', true)
            ->assertJsonPath('chat.qualification.questions.0.key', 'name');
    }

    public function test_website_chat_qualification_auto_qualifies_and_routes_lead(): void
    {
        config()->set('enrichment.email.check_mx', false);

        $tenant = Tenant::factory()->create([
            'slug' => 'chat-qualification',
            'settings' => [
                'bot' => [
                    'enabled' => true,
                    'channels' => [
                        'website_chat' => true,
                        'whatsapp' => false,
                    ],
                    'default_reply' => 'Let us qualify your request.',
                    'completion_reply' => 'Qualification complete.',
                    'qualification' => [
                        'enabled' => true,
                        'auto_qualify' => true,
                        'questions' => [
                            [
                                'key' => 'full_name',
                                'question' => 'What is your full name?',
                                'field' => 'full_name',
                                'required' => true,
                            ],
                            [
                                'key' => 'company',
                                'question' => 'What is your company?',
                                'field' => 'company',
                                'required' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $owner = User::factory()->sales()->create(['tenant_id' => $tenant->id]);

        AssignmentRule::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Bot Routing',
            'is_active' => true,
            'priority' => 1,
            'strategy' => AssignmentRule::STRATEGY_RULES_ENGINE,
            'auto_assign_on_intake' => true,
            'auto_assign_on_import' => true,
            'conditions' => [],
            'settings' => [
                'actions' => [
                    [
                        'type' => AssignmentRule::ACTION_ASSIGN,
                        'owner_id' => $owner->id,
                    ],
                ],
            ],
        ]);

        $first = $this->withHeader('X-Tenant-Slug', $tenant->slug)
            ->postJson('/api/public/chat/message', [
                'session_id' => 'chat-session-1',
                'message' => 'Hi',
                'email' => 'lead@smartcedra.com',
            ])
            ->assertOk()
            ->assertJsonPath('bot_reply', 'What is your full name?');

        $leadId = (int) $first->json('lead.id');

        $this->withHeader('X-Tenant-Slug', $tenant->slug)
            ->postJson('/api/public/chat/message', [
                'session_id' => 'chat-session-1',
                'message' => 'Ahamed Ali',
            ])
            ->assertOk()
            ->assertJsonPath('bot_reply', 'What is your company?');

        $this->withHeader('X-Tenant-Slug', $tenant->slug)
            ->postJson('/api/public/chat/message', [
                'session_id' => 'chat-session-1',
                'message' => 'Smart Cedra',
            ])
            ->assertOk()
            ->assertJsonPath('bot_reply', 'Qualification complete.')
            ->assertJsonPath('qualified', true);

        $lead = Lead::query()->withoutTenancy()->whereKey($leadId)->firstOrFail();

        $this->assertSame('Ahamed', $lead->first_name);
        $this->assertSame('Ali', $lead->last_name);
        $this->assertSame('Smart Cedra', $lead->company);
        $this->assertSame('qualified', $lead->status);
        $this->assertSame($owner->id, $lead->owner_id);

        $this->assertDatabaseHas('messages', [
            'tenant_id' => $tenant->id,
            'lead_id' => $leadId,
            'channel' => 'website_chat',
            'direction' => 'inbound',
        ]);

        $this->assertDatabaseHas('messages', [
            'tenant_id' => $tenant->id,
            'lead_id' => $leadId,
            'channel' => 'website_chat',
            'direction' => 'outbound',
            'provider' => 'bot',
        ]);

        $this->assertDatabaseHas('realtime_events', [
            'tenant_id' => $tenant->id,
            'subject_type' => Lead::class,
            'subject_id' => $leadId,
            'event_name' => 'bot.lead.qualified',
        ]);
    }

    public function test_whatsapp_inbound_message_triggers_bot_handoff_and_reply(): void
    {
        config()->set('messaging.providers.whatsapp', 'mock');

        $tenant = Tenant::factory()->create([
            'slug' => 'wa-bot',
            'settings' => [
                'bot' => [
                    'enabled' => true,
                    'channels' => [
                        'website_chat' => false,
                        'whatsapp' => true,
                    ],
                    'handoff_keywords' => ['agent', 'human'],
                    'handoff_reply' => 'Connecting you to a human agent.',
                    'qualification' => [
                        'enabled' => false,
                    ],
                    'whatsapp' => [
                        'phone_number_id' => '12345',
                    ],
                ],
            ],
        ]);

        $owner = User::factory()->sales()->create(['tenant_id' => $tenant->id]);

        AssignmentRule::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'name' => 'WA Handoff Routing',
            'is_active' => true,
            'priority' => 1,
            'strategy' => AssignmentRule::STRATEGY_RULES_ENGINE,
            'auto_assign_on_intake' => true,
            'auto_assign_on_import' => true,
            'conditions' => [],
            'settings' => [
                'actions' => [
                    [
                        'type' => AssignmentRule::ACTION_ASSIGN,
                        'owner_id' => $owner->id,
                    ],
                ],
            ],
        ]);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'entry-1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => [
                            'phone_number_id' => '12345',
                        ],
                        'contacts' => [[
                            'profile' => [
                                'name' => 'Nora Ahmed',
                            ],
                            'wa_id' => '966500000001',
                        ]],
                        'messages' => [[
                            'from' => '966500000001',
                            'id' => 'wamid.inbound.1',
                            'timestamp' => (string) now()->timestamp,
                            'type' => 'text',
                            'text' => [
                                'body' => 'Need human agent now',
                            ],
                        ]],
                    ],
                ]],
            ]],
        ];

        $this->postJson('/api/webhooks/whatsapp/meta', $payload)
            ->assertOk()
            ->assertJsonPath('processed', 0)
            ->assertJsonPath('inbound_captured', 1);

        $lead = Lead::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->where('phone', '+966500000001')
            ->firstOrFail();

        $this->assertSame('Nora', $lead->first_name);
        $this->assertSame('Ahmed', $lead->last_name);
        $this->assertSame('whatsapp_bot', $lead->source);
        $this->assertSame($owner->id, $lead->owner_id);

        $this->assertDatabaseHas('messages', [
            'tenant_id' => $tenant->id,
            'lead_id' => $lead->id,
            'channel' => 'whatsapp',
            'direction' => 'inbound',
            'provider_message_id' => 'wamid.inbound.1',
        ]);

        $reply = Message::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->where('lead_id', $lead->id)
            ->where('channel', 'whatsapp')
            ->where('direction', 'outbound')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('sent', $reply->status);
        $this->assertSame('mock', $reply->provider);
        $this->assertSame('Connecting you to a human agent.', $reply->body);

        $this->assertDatabaseHas('realtime_events', [
            'tenant_id' => $tenant->id,
            'subject_type' => Lead::class,
            'subject_id' => $lead->id,
            'event_name' => 'bot.handoff.requested',
        ]);

        $events = RealtimeEvent::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->where('subject_id', $lead->id)
            ->pluck('event_name')
            ->all();

        $this->assertContains('bot.handoff.requested', $events);
    }
}
