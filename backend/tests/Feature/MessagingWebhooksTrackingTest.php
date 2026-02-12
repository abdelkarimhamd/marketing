<?php

namespace Tests\Feature;

use App\Jobs\SendCampaignMessageJob;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\MessageTrackingToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessagingWebhooksTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_job_uses_mock_email_provider_and_injects_tracking(): void
    {
        config()->set('messaging.providers.email', 'mock');

        $tenant = Tenant::factory()->create();

        $message = Message::query()->create([
            'tenant_id' => $tenant->id,
            'direction' => 'outbound',
            'status' => 'queued',
            'channel' => 'email',
            'to' => 'lead@example.test',
            'from' => 'hello@example.test',
            'subject' => 'Welcome',
            'body' => '<html><body><a href="https://example.com/offer">Offer</a></body></html>',
            'meta' => [],
        ]);

        (new SendCampaignMessageJob($message->id))->handle(
            app(\App\Messaging\MessageDispatcher::class),
            app(\App\Services\MessageStatusService::class),
        );

        $message->refresh();

        $this->assertSame('sent', $message->status);
        $this->assertSame('mock', $message->provider);
        $this->assertNotNull($message->provider_message_id);
        $this->assertStringContainsString('/track/open/', (string) $message->body);
        $this->assertStringContainsString('/track/click/', (string) $message->body);
        $this->assertTrue((bool) data_get($message->meta, 'tracking.prepared'));
    }

    public function test_open_pixel_updates_message_status_and_returns_gif(): void
    {
        $tenant = Tenant::factory()->create();

        $message = Message::query()->create([
            'tenant_id' => $tenant->id,
            'direction' => 'outbound',
            'status' => 'sent',
            'channel' => 'email',
            'to' => 'lead@example.test',
            'provider' => 'mock',
            'provider_message_id' => 'mock-email-open',
            'body' => '<p>Hello</p>',
        ]);

        $token = app(MessageTrackingToken::class)->makeOpenToken(
            tenantId: $tenant->id,
            messageId: $message->id,
        );

        $response = $this->get('/track/open/'.$token);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/gif');

        $message->refresh();
        $this->assertNotNull($message->opened_at);
        $this->assertSame('opened', $message->status);
    }

    public function test_click_redirect_updates_message_status_and_redirects(): void
    {
        $tenant = Tenant::factory()->create();

        $message = Message::query()->create([
            'tenant_id' => $tenant->id,
            'direction' => 'outbound',
            'status' => 'sent',
            'channel' => 'email',
            'to' => 'lead@example.test',
            'provider' => 'mock',
            'provider_message_id' => 'mock-email-click',
            'body' => '<p>Hello</p>',
        ]);

        $destination = 'https://example.com/path?src=email';
        $token = app(MessageTrackingToken::class)->makeClickToken(
            tenantId: $tenant->id,
            messageId: $message->id,
            targetUrl: $destination,
        );

        $this->get('/track/click/'.$token)
            ->assertRedirect($destination);

        $message->refresh();
        $this->assertNotNull($message->clicked_at);
        $this->assertSame('clicked', $message->status);
    }

    public function test_email_webhook_updates_message_status(): void
    {
        $tenant = Tenant::factory()->create();

        $message = Message::query()->create([
            'tenant_id' => $tenant->id,
            'direction' => 'outbound',
            'status' => 'sent',
            'channel' => 'email',
            'to' => 'lead@example.test',
            'provider' => 'mock',
            'provider_message_id' => 'mock-email-status',
        ]);

        $this->postJson('/api/webhooks/email/mock', [
            'provider_message_id' => 'mock-email-status',
            'status' => 'delivered',
        ])->assertOk()
            ->assertJsonPath('processed', 1);

        $message->refresh();
        $this->assertSame('delivered', $message->status);
        $this->assertNotNull($message->delivered_at);

        $this->assertDatabaseHas('webhooks_inbox', [
            'provider' => 'mock',
            'status' => 'processed',
        ]);
    }

    public function test_twilio_sms_webhook_maps_status_and_updates_message(): void
    {
        $tenant = Tenant::factory()->create();

        $message = Message::query()->create([
            'tenant_id' => $tenant->id,
            'direction' => 'outbound',
            'status' => 'sent',
            'channel' => 'sms',
            'to' => '+15550001111',
            'provider' => 'twilio',
            'provider_message_id' => 'SM123456',
        ]);

        $this->post('/api/webhooks/sms/twilio', [
            'MessageSid' => 'SM123456',
            'MessageStatus' => 'delivered',
        ])->assertOk()
            ->assertJsonPath('processed', 1);

        $message->refresh();
        $this->assertSame('delivered', $message->status);
        $this->assertNotNull($message->delivered_at);
    }

    public function test_meta_whatsapp_webhook_updates_read_status_and_verification(): void
    {
        config()->set('messaging.meta_whatsapp.verify_token', 'verify-token');

        $tenant = Tenant::factory()->create();

        $message = Message::query()->create([
            'tenant_id' => $tenant->id,
            'direction' => 'outbound',
            'status' => 'sent',
            'channel' => 'whatsapp',
            'to' => '+15550002222',
            'provider' => 'meta',
            'provider_message_id' => 'wamid.HBgM123',
        ]);

        $this->get('/api/webhooks/whatsapp/meta?hub.mode=subscribe&hub.verify_token=verify-token&hub.challenge=42')
            ->assertOk()
            ->assertSeeText('42');

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'entry-id',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'statuses' => [[
                            'id' => 'wamid.HBgM123',
                            'status' => 'read',
                            'timestamp' => (string) now()->timestamp,
                        ]],
                    ],
                ]],
            ]],
        ];

        $this->postJson('/api/webhooks/whatsapp/meta', $payload)
            ->assertOk()
            ->assertJsonPath('processed', 1);

        $message->refresh();
        $this->assertSame('read', $message->status);
        $this->assertNotNull($message->read_at);
    }
}
