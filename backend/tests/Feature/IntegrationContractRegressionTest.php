<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationContractRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_meta_whatsapp_verification_contract_accepts_valid_token_and_rejects_invalid_token(): void
    {
        config()->set('messaging.meta_whatsapp.verify_token', 'qa-verify-token');

        $this->get('/api/webhooks/whatsapp/meta?hub.mode=subscribe&hub.verify_token=qa-verify-token&hub.challenge=abc123')
            ->assertOk()
            ->assertSeeText('abc123');

        $this->get('/api/webhooks/whatsapp/meta?hub.mode=subscribe&hub.verify_token=wrong-token&hub.challenge=abc123')
            ->assertForbidden();
    }

    public function test_twilio_webhook_contract_maps_status_and_error_payload_to_message_state(): void
    {
        $tenant = Tenant::factory()->create();

        $message = Message::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'direction' => 'outbound',
            'status' => 'sent',
            'channel' => 'sms',
            'to' => '+15550003333',
            'from' => '+15550004444',
            'provider' => 'twilio',
            'provider_message_id' => 'SM-QA-001',
            'body' => 'QA message',
        ]);

        $this->post('/api/webhooks/sms/twilio', [
            'MessageSid' => 'SM-QA-001',
            'MessageStatus' => 'failed',
            'ErrorCode' => '30007',
        ], [
            'X-Twilio-Signature' => 'twilio-signature-qa',
            'Accept' => 'application/json',
        ])->assertOk()
            ->assertJsonPath('processed', 1);

        $message->refresh();

        $this->assertSame('failed', $message->status);
        $this->assertNotNull($message->failed_at);
        $this->assertStringContainsString('30007', (string) $message->error_message);

        $this->assertDatabaseHas('webhooks_inbox', [
            'provider' => 'twilio',
            'external_id' => '',
            'status' => 'processed',
            'signature' => 'twilio-signature-qa',
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_email_webhook_contract_persists_signature_and_marks_unknown_payload_as_ignored(): void
    {
        $this->postJson('/api/webhooks/email/mock', [
            'event' => 'delivery_report',
            'meta' => ['raw' => true],
        ], [
            'X-Signature' => 'email-signature-qa',
        ])->assertOk()
            ->assertJsonPath('processed', 0);

        $this->assertDatabaseHas('webhooks_inbox', [
            'provider' => 'mock',
            'status' => 'ignored',
            'signature' => 'email-signature-qa',
        ]);
    }
}
