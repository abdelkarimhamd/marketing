<?php

namespace Tests\Feature;

use App\Jobs\GenerateCampaignMessagesJob;
use App\Jobs\LaunchCampaignJob;
use App\Jobs\SendCampaignMessageJob;
use App\Models\Campaign;
use App\Models\CampaignStep;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Segment;
use App\Models\Template;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CampaignEngineService;
use App\Services\SegmentEvaluationService;
use App\Services\VariableRenderingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CampaignEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_crud_wizard_and_launch_work_for_admin(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);

        $segment = Segment::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'All Leads',
            'rules_json' => null,
        ]);

        $template = Template::factory()->create([
            'tenant_id' => $tenant->id,
            'channel' => 'email',
        ]);

        Sanctum::actingAs($admin);

        $create = $this->postJson('/api/admin/campaigns', [
            'name' => 'Q1 Welcome Campaign',
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'campaign_type' => Campaign::TYPE_BROADCAST,
        ])->assertCreated();

        $campaignId = (int) $create->json('campaign.id');

        $this->postJson('/api/admin/campaigns/'.$campaignId.'/wizard', [
            'step' => 'audience',
            'segment_id' => $segment->id,
        ])->assertOk()
            ->assertJsonPath('campaign.settings.wizard.audience', true);

        $this->postJson('/api/admin/campaigns/'.$campaignId.'/wizard', [
            'step' => 'content',
            'template_id' => $template->id,
            'channel' => 'email',
        ])->assertOk()
            ->assertJsonPath('campaign.settings.wizard.content', true);

        $startAt = now()->addDay()->toDateTimeString();

        $this->postJson('/api/admin/campaigns/'.$campaignId.'/wizard', [
            'step' => 'schedule',
            'journey_type' => 'default',
            'campaign_type' => Campaign::TYPE_DRIP,
            'start_at' => $startAt,
            'stop_rules' => [
                'opt_out' => true,
                'won_lost' => true,
                'replied' => true,
                'fatigue_enabled' => true,
                'fatigue_threshold_messages' => 7,
                'fatigue_reengagement_messages' => 2,
                'fatigue_sunset' => true,
            ],
            'drip_steps' => [
                ['name' => 'Day 0', 'day' => 0, 'step_order' => 1, 'template_id' => $template->id],
                ['name' => 'Day 2', 'day' => 2, 'step_order' => 2, 'template_id' => $template->id],
                ['name' => 'Day 7', 'day' => 7, 'step_order' => 3, 'template_id' => $template->id],
            ],
        ])->assertOk()
            ->assertJsonPath('campaign.campaign_type', Campaign::TYPE_DRIP)
            ->assertJsonPath('campaign.settings.journey_type', 'default')
            ->assertJsonPath('campaign.settings.stop_rules.fatigue_enabled', true)
            ->assertJsonPath('campaign.settings.stop_rules.fatigue_threshold_messages', 7)
            ->assertJsonPath('campaign.settings.wizard.schedule', true)
            ->assertJsonPath('campaign.settings.wizard_completed', true);

        $this->assertDatabaseHas('campaigns', [
            'id' => $campaignId,
            'tenant_id' => $tenant->id,
            'campaign_type' => Campaign::TYPE_DRIP,
        ]);

        $this->assertDatabaseHas('campaign_steps', [
            'campaign_id' => $campaignId,
            'step_order' => 2,
            'delay_minutes' => 2880,
        ]);

        Queue::fake();

        $this->postJson('/api/admin/campaigns/'.$campaignId.'/launch')
            ->assertOk()
            ->assertJsonPath('message', 'Campaign launch queued successfully.')
            ->assertJsonPath('campaign.status', Campaign::STATUS_RUNNING);

        Queue::assertPushed(LaunchCampaignJob::class, 1);

        $this->getJson('/api/admin/campaigns?search=Q1')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $campaignId);

        $this->patchJson('/api/admin/campaigns/'.$campaignId, [
            'name' => 'Q1 Welcome Campaign Updated',
        ])->assertOk()
            ->assertJsonPath('campaign.name', 'Q1 Welcome Campaign Updated');

        $this->deleteJson('/api/admin/campaigns/'.$campaignId)
            ->assertOk()
            ->assertJsonPath('message', 'Campaign deleted successfully.');

        $this->assertSoftDeleted('campaigns', ['id' => $campaignId]);
    }

    public function test_launch_campaign_job_dispatches_one_generation_job_per_active_drip_step(): void
    {
        $tenant = Tenant::factory()->create();
        $segment = Segment::factory()->create(['tenant_id' => $tenant->id]);
        $template = Template::factory()->create(['tenant_id' => $tenant->id, 'channel' => 'email']);

        $campaign = Campaign::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'name' => 'Drip Pipeline',
            'slug' => 'drip-pipeline',
            'channel' => 'email',
            'campaign_type' => Campaign::TYPE_DRIP,
            'status' => Campaign::STATUS_RUNNING,
            'start_at' => now()->addHours(2),
            'settings' => [],
            'metrics' => [],
        ]);

        $stepOne = CampaignStep::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'campaign_id' => $campaign->id,
            'template_id' => $template->id,
            'name' => 'Day 0',
            'step_order' => 1,
            'channel' => 'email',
            'delay_minutes' => 0,
            'is_active' => true,
            'settings' => [],
        ]);

        $stepTwo = CampaignStep::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'campaign_id' => $campaign->id,
            'template_id' => $template->id,
            'name' => 'Day 2',
            'step_order' => 2,
            'channel' => 'email',
            'delay_minutes' => 2880,
            'is_active' => true,
            'settings' => [],
        ]);

        CampaignStep::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'campaign_id' => $campaign->id,
            'template_id' => $template->id,
            'name' => 'Inactive',
            'step_order' => 3,
            'channel' => 'email',
            'delay_minutes' => 4320,
            'is_active' => false,
            'settings' => [],
        ]);

        Queue::fake();

        (new LaunchCampaignJob($campaign->id))->handle(app(CampaignEngineService::class));

        Queue::assertPushed(GenerateCampaignMessagesJob::class, 2);

        Queue::assertPushed(GenerateCampaignMessagesJob::class, function (GenerateCampaignMessagesJob $job) use ($campaign, $stepOne): bool {
            return $job->campaignId === $campaign->id && $job->campaignStepId === $stepOne->id;
        });

        Queue::assertPushed(GenerateCampaignMessagesJob::class, function (GenerateCampaignMessagesJob $job) use ($campaign, $stepTwo): bool {
            return $job->campaignId === $campaign->id && $job->campaignStepId === $stepTwo->id;
        });
    }

    public function test_generate_campaign_messages_job_applies_stop_rules_and_queues_send_jobs(): void
    {
        $tenant = Tenant::factory()->create();
        $segment = Segment::factory()->create(['tenant_id' => $tenant->id, 'rules_json' => null]);
        $template = Template::factory()->create(['tenant_id' => $tenant->id, 'channel' => 'email']);

        $campaign = Campaign::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'name' => 'Broadcast Campaign',
            'slug' => 'broadcast-campaign',
            'channel' => 'email',
            'campaign_type' => Campaign::TYPE_BROADCAST,
            'status' => Campaign::STATUS_RUNNING,
            'settings' => [
                'stop_rules' => [
                    'opt_out' => true,
                    'won_lost' => true,
                    'replied' => true,
                ],
            ],
            'metrics' => [],
        ]);

        $deliverableLead = Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'deliverable@example.test',
            'status' => 'new',
            'email_consent' => true,
        ]);

        $wonLead = Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'won@example.test',
            'status' => 'won',
            'email_consent' => true,
        ]);

        $optedOutLead = Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'opted-out@example.test',
            'status' => 'new',
            'email_consent' => false,
        ]);

        $repliedLead = Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'replied@example.test',
            'status' => 'new',
            'email_consent' => true,
        ]);

        $missingDestinationLead = Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => null,
            'phone' => null,
            'status' => 'new',
            'email_consent' => true,
        ]);

        $alreadyQueuedLead = Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'already-queued@example.test',
            'status' => 'new',
            'email_consent' => true,
        ]);

        Message::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'campaign_id' => $campaign->id,
            'lead_id' => $repliedLead->id,
            'template_id' => $template->id,
            'direction' => 'inbound',
            'status' => 'received',
            'channel' => 'email',
            'to' => 'inbox@example.test',
            'from' => $repliedLead->email,
            'subject' => 'Reply',
            'body' => 'Interested',
        ]);

        Message::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'campaign_id' => $campaign->id,
            'lead_id' => $alreadyQueuedLead->id,
            'template_id' => $template->id,
            'direction' => 'outbound',
            'status' => 'queued',
            'channel' => 'email',
            'to' => $alreadyQueuedLead->email,
            'from' => 'hello@example.com',
            'subject' => 'Existing',
            'body' => 'Existing body',
        ]);

        Queue::fake();

        $job = new GenerateCampaignMessagesJob($campaign->id);
        $job->handle(
            app(CampaignEngineService::class),
            app(SegmentEvaluationService::class),
            app(VariableRenderingService::class)
        );

        $this->assertDatabaseHas('messages', [
            'tenant_id' => $tenant->id,
            'campaign_id' => $campaign->id,
            'lead_id' => $deliverableLead->id,
            'direction' => 'outbound',
            'status' => 'queued',
            'channel' => 'email',
            'to' => 'deliverable@example.test',
        ]);

        $this->assertDatabaseMissing('messages', [
            'tenant_id' => $tenant->id,
            'campaign_id' => $campaign->id,
            'lead_id' => $wonLead->id,
            'direction' => 'outbound',
        ]);

        $this->assertDatabaseMissing('messages', [
            'tenant_id' => $tenant->id,
            'campaign_id' => $campaign->id,
            'lead_id' => $optedOutLead->id,
            'direction' => 'outbound',
        ]);

        $this->assertDatabaseMissing('messages', [
            'tenant_id' => $tenant->id,
            'campaign_id' => $campaign->id,
            'lead_id' => $repliedLead->id,
            'direction' => 'outbound',
        ]);

        $this->assertDatabaseMissing('messages', [
            'tenant_id' => $tenant->id,
            'campaign_id' => $campaign->id,
            'lead_id' => $missingDestinationLead->id,
            'direction' => 'outbound',
        ]);

        Queue::assertPushed(SendCampaignMessageJob::class, 1);
        Queue::assertPushed(SendCampaignMessageJob::class, function (SendCampaignMessageJob $job): bool {
            return $job->queue === 'default';
        });
    }

    public function test_generate_campaign_messages_job_renders_whatsapp_rich_media_meta(): void
    {
        $tenant = Tenant::factory()->create();
        $segment = Segment::factory()->create(['tenant_id' => $tenant->id, 'rules_json' => null]);
        $template = Template::factory()->whatsapp()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_template_name' => null,
            'whatsapp_variables' => [],
            'settings' => [
                'whatsapp' => [
                    'message_type' => 'image',
                    'media' => [
                        'link' => 'https://cdn.example.test/promo-{{city}}.png',
                        'caption' => 'Hello {{first_name}}',
                    ],
                ],
            ],
        ]);

        $campaign = Campaign::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'name' => 'WhatsApp Rich Media Campaign',
            'slug' => 'whatsapp-rich-media-campaign',
            'channel' => 'whatsapp',
            'campaign_type' => Campaign::TYPE_BROADCAST,
            'status' => Campaign::STATUS_RUNNING,
            'settings' => [],
            'metrics' => [],
        ]);

        $lead = Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Ahamed',
            'city' => 'Riyadh',
            'phone' => '+15550003333',
        ]);

        Queue::fake();

        $job = new GenerateCampaignMessagesJob($campaign->id);
        $job->handle(
            app(CampaignEngineService::class),
            app(SegmentEvaluationService::class),
            app(VariableRenderingService::class)
        );

        $message = Message::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->where('campaign_id', $campaign->id)
            ->where('lead_id', $lead->id)
            ->where('channel', 'whatsapp')
            ->where('direction', 'outbound')
            ->firstOrFail();

        $this->assertSame('Hello Ahamed', $message->body);
        $this->assertSame('image', data_get($message->meta, 'message_type'));
        $this->assertSame('https://cdn.example.test/promo-Riyadh.png', data_get($message->meta, 'media.link'));
        $this->assertSame('Hello Ahamed', data_get($message->meta, 'media.caption'));

        Queue::assertPushed(SendCampaignMessageJob::class, 1);
    }

    public function test_generate_campaign_messages_job_applies_dynamic_personalization_blocks_for_email_templates(): void
    {
        $tenant = Tenant::factory()->create();
        $segment = Segment::factory()->create(['tenant_id' => $tenant->id, 'rules_json' => null]);
        $template = Template::factory()->create([
            'tenant_id' => $tenant->id,
            'channel' => 'email',
            'subject' => '{{#if city=Riyadh}}Riyadh Offer{{else}}Global Offer{{/if}}',
            'content' => '{{#lang ar}}مرحبا {{first_name|عميل}}{{/lang}}{{#lang en}}Hello {{first_name|Customer}}{{/lang}}',
        ]);

        $campaign = Campaign::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'name' => 'Personalized Campaign',
            'slug' => 'personalized-campaign',
            'channel' => 'email',
            'campaign_type' => Campaign::TYPE_BROADCAST,
            'status' => Campaign::STATUS_RUNNING,
            'settings' => [],
            'metrics' => [],
        ]);

        $leadArabic = Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'ar@example.test',
            'first_name' => null,
            'city' => 'Riyadh',
            'locale' => 'ar_SA',
            'email_consent' => true,
        ]);

        $leadEnglish = Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'en@example.test',
            'first_name' => 'Adam',
            'city' => 'Jeddah',
            'locale' => 'en_US',
            'email_consent' => true,
        ]);

        Queue::fake();

        $job = new GenerateCampaignMessagesJob($campaign->id);
        $job->handle(
            app(CampaignEngineService::class),
            app(SegmentEvaluationService::class),
            app(VariableRenderingService::class)
        );

        $arabicMessage = Message::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->where('campaign_id', $campaign->id)
            ->where('lead_id', $leadArabic->id)
            ->where('direction', 'outbound')
            ->firstOrFail();

        $englishMessage = Message::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->where('campaign_id', $campaign->id)
            ->where('lead_id', $leadEnglish->id)
            ->where('direction', 'outbound')
            ->firstOrFail();

        $this->assertSame('Riyadh Offer', $arabicMessage->subject);
        $this->assertSame('مرحبا عميل', $arabicMessage->body);
        $this->assertSame('Global Offer', $englishMessage->subject);
        $this->assertSame('Hello Adam', $englishMessage->body);
        Queue::assertPushed(SendCampaignMessageJob::class, 2);
    }

    public function test_generate_campaign_messages_job_suppresses_fatigued_unengaged_leads(): void
    {
        $tenant = Tenant::factory()->create();
        $segment = Segment::factory()->create(['tenant_id' => $tenant->id, 'rules_json' => null]);
        $template = Template::factory()->create(['tenant_id' => $tenant->id, 'channel' => 'email']);

        $campaign = Campaign::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'name' => 'Fatigue Suppression Campaign',
            'slug' => 'fatigue-suppression-campaign',
            'channel' => 'email',
            'campaign_type' => Campaign::TYPE_BROADCAST,
            'status' => Campaign::STATUS_RUNNING,
            'settings' => [
                'stop_rules' => [
                    'opt_out' => false,
                    'won_lost' => false,
                    'replied' => false,
                    'fatigue_enabled' => true,
                    'fatigue_threshold_messages' => 2,
                    'fatigue_reengagement_messages' => 1,
                    'fatigue_sunset' => true,
                ],
            ],
            'metrics' => [],
        ]);

        $lead = Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'fatigued@example.test',
            'email_consent' => true,
            'status' => 'new',
        ]);

        Message::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'lead_id' => $lead->id,
            'direction' => 'outbound',
            'status' => 'sent',
            'channel' => 'email',
            'to' => $lead->email,
            'from' => 'sender@example.test',
            'subject' => 'Touch 1',
            'body' => 'First touch',
            'sent_at' => now()->subDays(4),
        ]);

        Message::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'lead_id' => $lead->id,
            'direction' => 'outbound',
            'status' => 'sent',
            'channel' => 'email',
            'to' => $lead->email,
            'from' => 'sender@example.test',
            'subject' => 'Touch 2',
            'body' => 'Second touch',
            'sent_at' => now()->subDays(3),
        ]);

        Queue::fake();

        $job = new GenerateCampaignMessagesJob($campaign->id);
        $job->handle(
            app(CampaignEngineService::class),
            app(SegmentEvaluationService::class),
            app(VariableRenderingService::class)
        );

        $this->assertDatabaseMissing('messages', [
            'tenant_id' => $tenant->id,
            'campaign_id' => $campaign->id,
            'lead_id' => $lead->id,
            'direction' => 'outbound',
        ]);

        $lead->refresh();
        $fatigueState = data_get($lead->settings, 'engagement_fatigue.channels.email', []);

        $this->assertIsArray($fatigueState);
        $this->assertTrue((bool) ($fatigueState['suppressed'] ?? false));
        $this->assertSame('suppressed', $fatigueState['state'] ?? null);
        $this->assertNotNull($fatigueState['suppressed_at'] ?? null);

        $this->assertDatabaseHas('activities', [
            'tenant_id' => $tenant->id,
            'type' => 'lead.fatigue.suppressed',
            'subject_type' => Lead::class,
            'subject_id' => $lead->id,
        ]);

        Queue::assertNothingPushed();
    }

    public function test_reengagement_campaign_allows_limited_attempts_then_applies_sunset_policy(): void
    {
        $tenant = Tenant::factory()->create();
        $segment = Segment::factory()->create(['tenant_id' => $tenant->id, 'rules_json' => null]);
        $template = Template::factory()->create(['tenant_id' => $tenant->id, 'channel' => 'email']);

        $lead = Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'sunset@example.test',
            'email_consent' => true,
            'status' => 'new',
        ]);

        Message::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'lead_id' => $lead->id,
            'direction' => 'outbound',
            'status' => 'sent',
            'channel' => 'email',
            'to' => $lead->email,
            'from' => 'sender@example.test',
            'subject' => 'Touch 1',
            'body' => 'First touch',
            'sent_at' => now()->subDays(4),
        ]);

        Message::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'lead_id' => $lead->id,
            'direction' => 'outbound',
            'status' => 'sent',
            'channel' => 'email',
            'to' => $lead->email,
            'from' => 'sender@example.test',
            'subject' => 'Touch 2',
            'body' => 'Second touch',
            'sent_at' => now()->subDays(3),
        ]);

        $reengagementCampaignOne = Campaign::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'name' => 'Reengagement 1',
            'slug' => 'reengagement-1',
            'channel' => 'email',
            'campaign_type' => Campaign::TYPE_BROADCAST,
            'status' => Campaign::STATUS_RUNNING,
            'settings' => [
                'journey_type' => 'reengagement',
                'stop_rules' => [
                    'opt_out' => false,
                    'won_lost' => false,
                    'replied' => false,
                    'fatigue_enabled' => true,
                    'fatigue_threshold_messages' => 2,
                    'fatigue_reengagement_messages' => 1,
                    'fatigue_sunset' => true,
                ],
            ],
            'metrics' => [],
        ]);

        Queue::fake();

        (new GenerateCampaignMessagesJob($reengagementCampaignOne->id))->handle(
            app(CampaignEngineService::class),
            app(SegmentEvaluationService::class),
            app(VariableRenderingService::class)
        );

        $this->assertDatabaseHas('messages', [
            'tenant_id' => $tenant->id,
            'campaign_id' => $reengagementCampaignOne->id,
            'lead_id' => $lead->id,
            'direction' => 'outbound',
            'status' => 'queued',
        ]);
        Queue::assertPushed(SendCampaignMessageJob::class, 1);

        $reengagementCampaignTwo = Campaign::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'name' => 'Reengagement 2',
            'slug' => 'reengagement-2',
            'channel' => 'email',
            'campaign_type' => Campaign::TYPE_BROADCAST,
            'status' => Campaign::STATUS_RUNNING,
            'settings' => [
                'journey_type' => 'reengagement',
                'stop_rules' => [
                    'opt_out' => false,
                    'won_lost' => false,
                    'replied' => false,
                    'fatigue_enabled' => true,
                    'fatigue_threshold_messages' => 2,
                    'fatigue_reengagement_messages' => 1,
                    'fatigue_sunset' => true,
                ],
            ],
            'metrics' => [],
        ]);

        Queue::fake();

        (new GenerateCampaignMessagesJob($reengagementCampaignTwo->id))->handle(
            app(CampaignEngineService::class),
            app(SegmentEvaluationService::class),
            app(VariableRenderingService::class)
        );

        $this->assertDatabaseMissing('messages', [
            'tenant_id' => $tenant->id,
            'campaign_id' => $reengagementCampaignTwo->id,
            'lead_id' => $lead->id,
            'direction' => 'outbound',
        ]);

        $lead->refresh();
        $fatigueState = data_get($lead->settings, 'engagement_fatigue.channels.email', []);
        $this->assertSame('sunset', $fatigueState['state'] ?? null);
        $this->assertNotNull($fatigueState['sunset_at'] ?? null);

        $this->assertDatabaseHas('activities', [
            'tenant_id' => $tenant->id,
            'type' => 'lead.fatigue.sunset',
            'subject_type' => Lead::class,
            'subject_id' => $lead->id,
        ]);

        Queue::assertNothingPushed();
    }

    public function test_sales_user_cannot_access_campaign_admin_module(): void
    {
        $tenant = Tenant::factory()->create();
        $sales = User::factory()->sales()->create(['tenant_id' => $tenant->id]);

        Sanctum::actingAs($sales);

        $this->getJson('/api/admin/campaigns')
            ->assertForbidden();
    }
}
