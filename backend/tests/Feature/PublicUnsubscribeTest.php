<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Tenant;
use App\Support\UnsubscribeToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicUnsubscribeTest extends TestCase
{
    use RefreshDatabase;

    public function test_unsubscribe_token_updates_lead_consent_and_creates_unsubscribe_log(): void
    {
        $tenant = Tenant::factory()->create();

        $lead = Lead::query()->create([
            'tenant_id' => $tenant->id,
            'email' => 'unsubscribe.me@example.test',
            'email_consent' => true,
            'source' => 'seed',
        ]);

        $token = app(UnsubscribeToken::class)->make(
            tenantId: $tenant->id,
            channel: 'email',
            value: (string) $lead->email,
            leadId: $lead->id,
        );

        $this->get('/unsubscribe/'.$token)
            ->assertOk()
            ->assertSeeText('Unsubscribe Confirmed');

        $lead->refresh();

        $this->assertFalse((bool) $lead->email_consent);
        $this->assertNotNull($lead->consent_updated_at);

        $this->assertDatabaseHas('unsubscribes', [
            'tenant_id' => $tenant->id,
            'lead_id' => $lead->id,
            'channel' => 'email',
            'value' => 'unsubscribe.me@example.test',
            'source' => 'unsubscribe_link',
        ]);

        $this->assertDatabaseHas('activities', [
            'tenant_id' => $tenant->id,
            'type' => 'lead.unsubscribed',
            'subject_type' => 'App\Models\Lead',
            'subject_id' => $lead->id,
        ]);
    }

    public function test_invalid_unsubscribe_token_returns_not_found(): void
    {
        $this->get('/unsubscribe/not-a-valid-token')
            ->assertNotFound();
    }
}
