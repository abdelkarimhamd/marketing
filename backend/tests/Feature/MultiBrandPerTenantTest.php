<?php

namespace Tests\Feature;

use App\Jobs\GenerateCampaignMessagesJob;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Segment;
use App\Models\Template;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CampaignEngineService;
use App\Services\SegmentEvaluationService;
use App\Services\TenantEmailConfigurationService;
use App\Services\VariableRenderingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MultiBrandPerTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_lead_intake_assigns_brand_using_brand_slug(): void
    {
        config(['enrichment.email.check_mx' => false]);

        $tenant = Tenant::factory()->create([
            'slug' => 'agency-tenant',
        ]);

        $brand = Brand::factory()->create([
            'tenant_id' => $tenant->id,
            'slug' => 'brand-alpha',
            'landing_domain' => 'alpha.example.test',
        ]);

        $this->withHeaders([
            'X-Tenant-Slug' => $tenant->slug,
            'X-Brand-Slug' => $brand->slug,
        ])->postJson('/api/public/leads', [
            'email' => 'alpha.lead@example.test',
            'first_name' => 'Alpha',
        ])
            ->assertCreated()
            ->assertJsonPath('lead.tenant_id', $tenant->id)
            ->assertJsonPath('lead.brand_id', $brand->id)
            ->assertJsonPath('brand.slug', 'brand-alpha');

        $this->assertDatabaseHas('leads', [
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'email' => 'alpha.lead@example.test',
        ]);
    }

    public function test_campaign_generation_uses_brand_sender_and_signature(): void
    {
        $tenant = Tenant::factory()->create();

        $brand = Brand::factory()->create([
            'tenant_id' => $tenant->id,
            'email_from_address' => 'brand-mailer@example.test',
            'signatures' => [
                'email_html' => '<p>Regards,<br>Brand Team</p>',
            ],
        ]);

        $lead = Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'first_name' => 'Nora',
            'email' => 'nora@example.test',
            'status' => 'new',
        ]);

        $segment = Segment::factory()->create([
            'tenant_id' => $tenant->id,
            'rules_json' => null,
        ]);

        $template = Template::factory()->create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'channel' => 'email',
            'subject' => 'Hello {{first_name}}',
            'content' => '<p>Welcome {{first_name}}</p>',
            'is_active' => true,
        ]);

        $campaign = Campaign::factory()->create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'channel' => 'email',
            'campaign_type' => Campaign::TYPE_BROADCAST,
            'status' => Campaign::STATUS_DRAFT,
            'start_at' => null,
            'end_at' => null,
        ]);

        $job = new GenerateCampaignMessagesJob((int) $campaign->id);
        $job->handle(
            app(CampaignEngineService::class),
            app(SegmentEvaluationService::class),
            app(VariableRenderingService::class),
            app(TenantEmailConfigurationService::class),
        );

        $message = Message::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->where('campaign_id', $campaign->id)
            ->where('lead_id', $lead->id)
            ->firstOrFail();

        $this->assertSame($brand->id, (int) $message->brand_id);
        $this->assertSame('brand-mailer@example.test', $message->from);
        $this->assertStringContainsString('Welcome Nora', (string) $message->body);
        $this->assertStringContainsString('Brand Team', (string) $message->body);
    }

    public function test_campaign_api_rejects_template_that_does_not_match_selected_brand(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create([
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($admin);

        $brandA = Brand::factory()->create([
            'tenant_id' => $tenant->id,
            'slug' => 'brand-a',
        ]);
        $brandB = Brand::factory()->create([
            'tenant_id' => $tenant->id,
            'slug' => 'brand-b',
        ]);

        $segment = Segment::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $template = Template::factory()->create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brandA->id,
            'channel' => 'email',
        ]);

        $this->postJson('/api/admin/campaigns?tenant_id='.$tenant->id, [
            'name' => 'Brand mismatch campaign',
            'brand_id' => $brandB->id,
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'campaign_type' => Campaign::TYPE_BROADCAST,
            'channel' => 'email',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Provided template_id does not belong to the selected brand.');
    }

    public function test_public_chat_persists_brand_on_lead_and_messages(): void
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'chat-brand-tenant',
        ]);

        $brand = Brand::factory()->create([
            'tenant_id' => $tenant->id,
            'slug' => 'chat-brand',
        ]);

        $response = $this->withHeaders([
            'X-Tenant-Slug' => $tenant->slug,
            'X-Brand-Slug' => $brand->slug,
        ])->postJson('/api/public/chat/message', [
            'session_id' => 'brand-session-1',
            'message' => 'Hello',
            'email' => 'chat.brand@example.test',
        ]);

        $response->assertOk()
            ->assertJsonPath('lead.brand_id', $brand->id)
            ->assertJsonPath('brand.slug', $brand->slug);

        $leadId = (int) $response->json('lead.id');

        $this->assertDatabaseHas('messages', [
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'lead_id' => $leadId,
            'channel' => 'website_chat',
            'direction' => 'inbound',
        ]);
    }
}
