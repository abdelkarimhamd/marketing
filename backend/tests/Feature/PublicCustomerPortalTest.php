<?php

namespace Tests\Feature;

use App\Models\AssignmentRule;
use App\Models\IntegrationConnection;
use App\Models\LeadForm;
use App\Models\LeadFormField;
use App\Models\Team;
use App\Models\TeamUser;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicCustomerPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_portal_returns_branded_configuration_and_active_forms(): void
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'portal-brand',
            'branding' => [
                'logo_url' => 'https://cdn.example.test/portal/logo.svg',
                'landing_theme' => 'enterprise',
            ],
            'settings' => [
                'portal' => [
                    'headline' => 'Smart Cedra Portal',
                    'features' => [
                        'request_quote' => true,
                        'book_demo' => true,
                        'upload_docs' => true,
                        'track_status' => true,
                    ],
                ],
            ],
        ]);

        $form = LeadForm::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Portal Intake',
            'slug' => 'portal-intake',
            'is_active' => true,
            'settings' => [],
        ]);

        LeadFormField::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'lead_form_id' => $form->id,
            'label' => 'Company Name',
            'source_key' => 'company_name',
            'map_to' => 'company',
            'sort_order' => 1,
            'is_required' => false,
            'validation_rules' => [],
        ]);

        $this->withHeader('X-Tenant-Slug', $tenant->slug)
            ->getJson('/api/public/portal')
            ->assertOk()
            ->assertJsonPath('tenant.id', $tenant->id)
            ->assertJsonPath('branding.landing_theme', 'enterprise')
            ->assertJsonPath('portal.headline', 'Smart Cedra Portal')
            ->assertJsonPath('portal.forms.0.slug', 'portal-intake')
            ->assertJsonPath('portal.forms.0.fields.0.source_key', 'company_name');
    }

    public function test_quote_request_creates_lead_and_triggers_routing_deal_action(): void
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'portal-quote',
        ]);

        AssignmentRule::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Portal Rule',
            'is_active' => true,
            'priority' => 10,
            'strategy' => AssignmentRule::STRATEGY_RULES_ENGINE,
            'auto_assign_on_intake' => true,
            'auto_assign_on_import' => true,
            'conditions' => [],
            'settings' => [
                'actions' => [
                    [
                        'type' => AssignmentRule::ACTION_CREATE_DEAL,
                        'status' => 'qualified',
                        'pipeline' => 'sales',
                        'stage' => 'quote',
                    ],
                ],
            ],
        ]);

        $response = $this->withHeader('X-Tenant-Slug', $tenant->slug)
            ->postJson('/api/public/portal/request-quote', [
                'first_name' => 'Nora',
                'email' => 'nora@example.test',
                'company' => 'Acme',
                'quote_budget' => '25000',
                'quote_timeline' => 'Q2',
                'message' => 'Need proposal for CRM rollout.',
            ])
            ->assertCreated()
            ->assertJsonPath('lead.source', 'portal_request_quote')
            ->assertJsonPath('lead.status', 'qualified')
            ->assertJsonPath('tracking_token', fn ($token) => is_string($token) && $token !== '')
            ->assertJsonPath('status_url', fn ($url) => is_string($url) && str_contains($url, '/api/public/portal/status/'));

        $leadId = (int) $response->json('lead.id');

        $this->assertDatabaseHas('activities', [
            'tenant_id' => $tenant->id,
            'subject_type' => 'App\\Models\\Lead',
            'subject_id' => $leadId,
            'type' => 'portal.quote.requested',
        ]);

        $this->assertDatabaseHas('activities', [
            'tenant_id' => $tenant->id,
            'subject_type' => 'App\\Models\\Lead',
            'subject_id' => $leadId,
            'type' => 'deal.created.from_rule',
        ]);

        $this->assertDatabaseHas('realtime_events', [
            'tenant_id' => $tenant->id,
            'subject_type' => 'App\\Models\\Lead',
            'subject_id' => $leadId,
            'event_name' => 'portal.quote.requested',
        ]);
    }

    public function test_demo_booking_issues_tracking_token_and_status_endpoint_returns_timeline(): void
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'portal-demo',
        ]);

        $preferredAt = Carbon::now()->addDay()->startOfHour()->toIso8601String();

        $create = $this->withHeader('X-Tenant-Slug', $tenant->slug)
            ->postJson('/api/public/portal/book-demo', [
                'first_name' => 'Omar',
                'email' => 'omar@example.test',
                'preferred_at' => $preferredAt,
                'booking_channel' => 'video',
                'message' => 'Please schedule a walkthrough.',
            ])
            ->assertCreated()
            ->assertJsonPath('lead.source', 'portal_book_demo')
            ->assertJsonPath('lead.status', 'demo_booked')
            ->assertJsonPath('appointment.id', fn ($id) => is_int($id) && $id > 0)
            ->assertJsonPath('tracking_token', fn ($token) => is_string($token) && $token !== '');

        $token = (string) $create->json('tracking_token');
        $leadId = (int) $create->json('lead.id');
        $appointmentId = (int) $create->json('appointment.id');

        $this->assertDatabaseHas('appointments', [
            'id' => $appointmentId,
            'tenant_id' => $tenant->id,
            'lead_id' => $leadId,
            'status' => 'booked',
            'source' => 'portal',
            'channel' => 'video',
        ]);

        $this->assertDatabaseHas('activities', [
            'tenant_id' => $tenant->id,
            'subject_type' => 'App\\Models\\Lead',
            'subject_id' => $leadId,
            'type' => 'appointment.booked',
        ]);

        $this->assertDatabaseHas('realtime_events', [
            'tenant_id' => $tenant->id,
            'subject_type' => 'App\\Models\\Lead',
            'subject_id' => $leadId,
            'event_name' => 'appointment.booked',
        ]);

        $this->getJson('/api/public/portal/status/'.$token)
            ->assertOk()
            ->assertJsonPath('tenant.id', $tenant->id)
            ->assertJsonPath('tracking.intent', 'book_demo')
            ->assertJsonPath('lead.id', $leadId)
            ->assertJsonPath('lead.source', 'portal_book_demo')
            ->assertJsonPath('timeline.0.type', fn ($type) => is_string($type) && $type !== '');
    }

    public function test_demo_booking_uses_agent_link_and_syncs_google_and_microsoft_calendars(): void
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'portal-sync',
            'settings' => [
                'portal' => [
                    'booking' => [
                        'deal_stage_on_booking' => 'meeting_booked',
                        'default_duration_minutes' => 45,
                    ],
                ],
            ],
        ]);

        $owner = User::factory()->sales()->create([
            'tenant_id' => $tenant->id,
            'settings' => [
                'booking' => [
                    'link' => 'https://book.example.test/agents/ali',
                ],
            ],
        ]);

        $team = Team::factory()->create([
            'tenant_id' => $tenant->id,
            'settings' => [
                'booking' => [
                    'link' => 'https://book.example.test/teams/sales',
                ],
            ],
        ]);

        TeamUser::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'team_id' => $team->id,
            'user_id' => $owner->id,
            'role' => 'member',
            'is_primary' => true,
        ]);

        IntegrationConnection::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'google_calendar',
            'name' => 'Google Calendar',
            'config' => [
                'calendar_id' => 'primary',
            ],
            'secrets' => [
                'access_token' => 'google-test-token',
            ],
            'capabilities' => ['calendar.write'],
            'is_active' => true,
        ]);

        IntegrationConnection::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'microsoft_calendar',
            'name' => 'Microsoft Calendar',
            'config' => [],
            'secrets' => [
                'access_token' => 'microsoft-test-token',
            ],
            'capabilities' => ['calendar.write'],
            'is_active' => true,
        ]);

        Http::fake([
            'https://www.googleapis.com/*' => Http::response([
                'id' => 'gcal_evt_123',
                'htmlLink' => 'https://calendar.google.com/event?eid=123',
            ], 200),
            'https://graph.microsoft.com/*' => Http::response([
                'id' => 'ms_evt_456',
                'webLink' => 'https://outlook.office.com/calendar/item/456',
            ], 201),
        ]);

        $preferredAt = Carbon::now()->addDay()->addHours(2)->startOfHour()->toIso8601String();

        $response = $this->withHeader('X-Tenant-Slug', $tenant->slug)
            ->postJson('/api/public/portal/book-demo', [
                'first_name' => 'Ali',
                'last_name' => 'Hassan',
                'email' => 'ali.hassan@example.test',
                'preferred_at' => $preferredAt,
                'booking_channel' => 'video',
                'message' => 'Book with assigned owner.',
                'owner_id' => $owner->id,
                'team_id' => $team->id,
            ])
            ->assertCreated()
            ->assertJsonPath('lead.owner_id', $owner->id)
            ->assertJsonPath('lead.team_id', $team->id)
            ->assertJsonPath('lead.status', 'meeting_booked')
            ->assertJsonPath('appointment.booking_link', 'https://book.example.test/agents/ali')
            ->assertJsonPath('appointment.external_refs.google.event_id', 'gcal_evt_123')
            ->assertJsonPath('appointment.external_refs.microsoft.event_id', 'ms_evt_456');

        $leadId = (int) $response->json('lead.id');
        $appointmentId = (int) $response->json('appointment.id');

        $this->assertDatabaseHas('activities', [
            'tenant_id' => $tenant->id,
            'subject_type' => 'App\\Models\\Lead',
            'subject_id' => $leadId,
            'type' => 'deal.stage_changed',
        ]);

        $this->assertDatabaseHas('realtime_events', [
            'tenant_id' => $tenant->id,
            'subject_type' => 'App\\Models\\Lead',
            'subject_id' => $leadId,
            'event_name' => 'deal.stage_changed',
        ]);

        $this->assertDatabaseHas('appointments', [
            'id' => $appointmentId,
            'tenant_id' => $tenant->id,
            'lead_id' => $leadId,
            'owner_id' => $owner->id,
            'team_id' => $team->id,
            'meeting_url' => 'https://calendar.google.com/event?eid=123',
        ]);

        $googleConnection = IntegrationConnection::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->where('provider', 'google_calendar')
            ->first();
        $microsoftConnection = IntegrationConnection::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->where('provider', 'microsoft_calendar')
            ->first();

        $this->assertNotNull($googleConnection?->last_synced_at);
        $this->assertNotNull($microsoftConnection?->last_synced_at);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'googleapis.com/calendar/v3/calendars/primary/events'));
        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'graph.microsoft.com/v1.0/me/events'));
    }

    public function test_upload_documents_links_files_to_lead_with_tracking_token(): void
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'portal-docs',
        ]);

        $create = $this->withHeader('X-Tenant-Slug', $tenant->slug)
            ->postJson('/api/public/portal/request-quote', [
                'first_name' => 'Huda',
                'email' => 'huda@example.test',
                'message' => 'Sending required documents.',
            ])
            ->assertCreated();

        $leadId = (int) $create->json('lead.id');
        $token = (string) $create->json('tracking_token');

        $file = UploadedFile::fake()->create('proposal.pdf', 120, 'application/pdf');

        $this->withHeader('X-Tenant-Slug', $tenant->slug)
            ->post('/api/public/portal/upload-documents', [
                'tracking_token' => $token,
                'kind' => 'proposal',
                'files' => [$file],
            ], [
                'Accept' => 'application/json',
            ])
            ->assertCreated()
            ->assertJsonPath('lead_id', $leadId)
            ->assertJsonPath('attachments.0.original_name', 'proposal.pdf');

        $this->assertDatabaseHas('attachments', [
            'tenant_id' => $tenant->id,
            'entity_type' => 'lead',
            'entity_id' => $leadId,
            'source' => 'portal',
            'original_name' => 'proposal.pdf',
        ]);

        $this->assertDatabaseHas('activities', [
            'tenant_id' => $tenant->id,
            'subject_type' => 'App\\Models\\Lead',
            'subject_id' => $leadId,
            'type' => 'portal.documents.uploaded',
        ]);
    }
}
