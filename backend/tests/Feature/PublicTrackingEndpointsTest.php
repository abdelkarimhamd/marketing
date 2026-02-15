<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TrackingEvent;
use App\Services\TrackingIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicTrackingEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_track_accepts_signed_batch_and_persists_events(): void
    {
        $tenant = Tenant::factory()->create();

        $payload = [
            'tenant_key' => $tenant->public_key,
            'events' => [
                [
                    'visitor_id' => 'vid_123',
                    'session_id' => 'sid_123',
                    'type' => 'pageview',
                    'url' => 'https://example.test/pricing',
                    'path' => '/pricing',
                    'referrer' => 'https://google.com',
                    'utm' => ['utm_source' => 'google', 'utm_medium' => 'cpc'],
                    'props' => ['title' => 'Pricing'],
                    'occurred_at' => now()->toIso8601String(),
                ],
            ],
        ];

        $signature = app(TrackingIngestionService::class)
            ->signatureForPayload($payload, (string) $tenant->public_key);

        $this->withHeader('X-Track-Signature', $signature)
            ->postJson('/api/public/track', $payload)
            ->assertAccepted()
            ->assertJsonPath('accepted', 1);

        $this->assertDatabaseHas('tracking_visitors', [
            'tenant_id' => $tenant->id,
            'visitor_id' => 'vid_123',
        ]);

        $this->assertDatabaseHas('tracking_events', [
            'tenant_id' => $tenant->id,
            'visitor_id' => 'vid_123',
            'event_type' => 'pageview',
            'path' => '/pricing',
        ]);
    }

    public function test_public_identify_links_visitor_to_existing_lead_and_backfills_events(): void
    {
        $tenant = Tenant::factory()->create();

        $lead = Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'tracked@example.test',
        ]);

        TrackingEvent::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'visitor_id' => 'vid_track_me',
            'session_id' => 'sid_track_me',
            'lead_id' => null,
            'event_type' => 'pageview',
            'url' => 'https://example.test/landing',
            'path' => '/landing',
            'occurred_at' => now(),
        ]);

        $payload = [
            'tenant_key' => $tenant->public_key,
            'visitor_id' => 'vid_track_me',
            'email' => 'tracked@example.test',
            'traits' => ['plan' => 'pro'],
        ];

        $signature = app(TrackingIngestionService::class)
            ->signatureForPayload($payload, (string) $tenant->public_key);

        $this->withHeader('X-Track-Signature', $signature)
            ->postJson('/api/public/identify', $payload)
            ->assertOk()
            ->assertJsonPath('visitor.lead_id', $lead->id);

        $this->assertDatabaseHas('tracking_visitors', [
            'tenant_id' => $tenant->id,
            'visitor_id' => 'vid_track_me',
            'lead_id' => $lead->id,
        ]);

        $this->assertDatabaseHas('tracking_events', [
            'tenant_id' => $tenant->id,
            'visitor_id' => 'vid_track_me',
            'lead_id' => $lead->id,
        ]);
    }

    public function test_public_track_rejects_invalid_signature(): void
    {
        $tenant = Tenant::factory()->create();

        $payload = [
            'tenant_key' => $tenant->public_key,
            'events' => [
                [
                    'visitor_id' => 'vid_bad_sig',
                    'type' => 'pageview',
                ],
            ],
        ];

        $this->withHeader('X-Track-Signature', 'invalid-signature')
            ->postJson('/api/public/track', $payload)
            ->assertForbidden();
    }
}
