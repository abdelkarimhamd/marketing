<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Lead;
use App\Models\Segment;
use App\Models\Template;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SegmentsTemplatesAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_segment_crud_and_preview_evaluation_work_with_and_or_rules(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);

        Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'match-1@example.test',
            'city' => 'Riyadh',
            'interest' => 'solar',
            'service' => 'implementation',
        ]);

        Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'match-2@example.test',
            'city' => 'Riyadh',
            'interest' => 'crm',
            'service' => 'implementation',
        ]);

        Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'no-match@example.test',
            'city' => 'Jeddah',
            'interest' => 'solar',
            'service' => 'consulting',
        ]);

        Sanctum::actingAs($admin);

        $rules = [
            'operator' => 'AND',
            'rules' => [
                [
                    'field' => 'city',
                    'operator' => 'equals',
                    'value' => 'Riyadh',
                ],
                [
                    'operator' => 'OR',
                    'rules' => [
                        [
                            'field' => 'interest',
                            'operator' => 'equals',
                            'value' => 'solar',
                        ],
                        [
                            'field' => 'service',
                            'operator' => 'equals',
                            'value' => 'implementation',
                        ],
                    ],
                ],
            ],
        ];

        $create = $this->postJson('/api/admin/segments', [
            'name' => 'Riyadh Qualified',
            'rules_json' => $rules,
            'is_active' => true,
        ])->assertCreated();

        $segmentId = (int) $create->json('segment.id');

        $this->getJson('/api/admin/segments/'.$segmentId.'/preview?include_rows=1')
            ->assertOk()
            ->assertJsonPath('matched_count', 2)
            ->assertJsonPath('leads.total', 2);

        $this->patchJson('/api/admin/segments/'.$segmentId, [
            'name' => 'Updated Segment',
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('segment.name', 'Updated Segment')
            ->assertJsonPath('segment.is_active', false);

        $this->deleteJson('/api/admin/segments/'.$segmentId)
            ->assertOk();

        $this->assertDatabaseMissing('segments', ['id' => $segmentId]);
    }

    public function test_template_crud_supports_email_sms_and_whatsapp_and_rendering(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);
        $lead = Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Ahamed',
            'company' => 'Marketion',
            'city' => 'Riyadh',
            'service' => 'implementation',
        ]);

        Sanctum::actingAs($admin);

        $emailCreate = $this->postJson('/api/admin/templates', [
            'name' => 'Welcome Email',
            'channel' => 'email',
            'subject' => 'Hello {{first_name}}',
            'html' => '<p>Welcome to {{company}}</p>',
        ])->assertCreated();

        $emailTemplateId = (int) $emailCreate->json('template.id');

        $this->postJson('/api/admin/templates/'.$emailTemplateId.'/render', [
            'lead_id' => $lead->id,
        ])->assertOk()
            ->assertJsonPath('rendered.subject', 'Hello Ahamed')
            ->assertJsonPath('rendered.html', '<p>Welcome to Marketion</p>');

        $smsCreate = $this->postJson('/api/admin/templates', [
            'name' => 'SMS Follow Up',
            'channel' => 'sms',
            'text' => 'Hi {{first_name}}, we will call {{company}} today.',
        ])->assertCreated();

        $smsTemplateId = (int) $smsCreate->json('template.id');

        $this->postJson('/api/admin/templates/'.$smsTemplateId.'/render', [
            'lead_id' => $lead->id,
        ])->assertOk()
            ->assertJsonPath('rendered.text', 'Hi Ahamed, we will call Marketion today.');

        $whatsappCreate = $this->postJson('/api/admin/templates', [
            'name' => 'WhatsApp Welcome',
            'channel' => 'whatsapp',
            'whatsapp_template_name' => 'welcome_{{city}}',
            'whatsapp_variables' => [
                'name' => '{{first_name}}',
                'service' => '{{service}}',
            ],
        ])->assertCreated();

        $whatsappTemplateId = (int) $whatsappCreate->json('template.id');

        $this->postJson('/api/admin/templates/'.$whatsappTemplateId.'/render', [
            'lead_id' => $lead->id,
        ])->assertOk()
            ->assertJsonPath('rendered.template_name', 'welcome_Riyadh')
            ->assertJsonPath('rendered.variables.name', 'Ahamed')
            ->assertJsonPath('rendered.variables.service', 'implementation');

        $this->patchJson('/api/admin/templates/'.$emailTemplateId, [
            'subject' => 'Updated {{first_name}}',
        ])->assertOk()
            ->assertJsonPath('template.subject', 'Updated {{first_name}}');

        $this->deleteJson('/api/admin/templates/'.$smsTemplateId)
            ->assertOk();

        $this->assertDatabaseMissing('templates', ['id' => $smsTemplateId]);
        $this->assertDatabaseHas('templates', ['id' => $emailTemplateId]);
        $this->assertDatabaseHas('templates', ['id' => $whatsappTemplateId]);
    }

    public function test_template_channel_validation_is_enforced(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/templates', [
            'name' => 'Invalid SMS',
            'channel' => 'sms',
        ])->assertUnprocessable();

        $this->postJson('/api/admin/templates', [
            'name' => 'Invalid WhatsApp',
            'channel' => 'whatsapp',
            'whatsapp_template_name' => 'wa_template_only',
        ])->assertUnprocessable();
    }

    public function test_whatsapp_template_render_supports_rich_media_and_carousel_settings(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);
        $lead = Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Ahamed',
            'city' => 'Riyadh',
            'service' => 'implementation',
        ]);

        $asset = Attachment::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'lead_id' => null,
            'entity_type' => 'media_library',
            'entity_id' => $tenant->id,
            'kind' => 'image',
            'source' => 'manual',
            'title' => 'City Banner',
            'description' => null,
            'storage_disk' => 'local',
            'storage_path' => 'attachments/tenants/'.$tenant->id.'/media_library/banner.png',
            'original_name' => 'banner.png',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'size_bytes' => 1024,
            'checksum_sha256' => null,
            'visibility' => 'private',
            'scan_status' => 'skipped',
            'scanned_at' => now(),
            'scan_engine' => null,
            'scan_result' => null,
            'uploaded_by' => $admin->id,
            'meta' => [],
            'expires_at' => null,
        ]);

        Sanctum::actingAs($admin);

        $create = $this->postJson('/api/admin/templates', [
            'name' => 'WhatsApp Image Template',
            'channel' => 'whatsapp',
            'whatsapp_template_name' => 'unused_template',
            'whatsapp_variables' => [],
            'settings' => [
                'whatsapp' => [
                    'message_type' => 'image',
                    'media' => [
                        'attachment_id' => $asset->id,
                        'link' => 'https://cdn.example.test/welcome-{{city}}.png',
                        'caption' => 'Hi {{first_name}}',
                    ],
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('template.settings.whatsapp.message_type', 'image');

        $templateId = (int) $create->json('template.id');

        $this->postJson('/api/admin/templates/'.$templateId.'/render', [
            'lead_id' => $lead->id,
        ])->assertOk()
            ->assertJsonPath('rendered.message_type', 'image')
            ->assertJsonPath('rendered.media.link', 'https://cdn.example.test/welcome-Riyadh.png')
            ->assertJsonPath('rendered.media.caption', 'Hi Ahamed');

        $this->patchJson('/api/admin/templates/'.$templateId, [
            'settings' => [
                'whatsapp' => [
                    'message_type' => 'carousel',
                    'text' => 'Please choose {{service}} package',
                    'carousel' => [
                        'body' => 'Cards for {{city}}',
                        'cards' => [
                            [
                                'id' => 'basic',
                                'title' => 'Basic {{city}}',
                                'description' => 'Starter package',
                            ],
                            [
                                'id' => 'pro',
                                'title' => 'Pro {{city}}',
                                'description' => 'Advanced package',
                                'media' => [
                                    'attachment_id' => $asset->id,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('template.settings.whatsapp.message_type', 'carousel');

        $this->postJson('/api/admin/templates/'.$templateId.'/render', [
            'lead_id' => $lead->id,
        ])->assertOk()
            ->assertJsonPath('rendered.message_type', 'carousel')
            ->assertJsonPath('rendered.text', 'Please choose implementation package')
            ->assertJsonPath('rendered.carousel.body', 'Cards for Riyadh')
            ->assertJsonPath('rendered.carousel.cards.0.title', 'Basic Riyadh');
    }

    public function test_template_render_preview_supports_dynamic_personalization_per_lead(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);
        $leadArabic = Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'first_name' => null,
            'city' => 'Riyadh',
            'locale' => 'ar_SA',
            'company' => null,
        ]);
        $leadEnglish = Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Adam',
            'city' => 'Dammam',
            'locale' => 'en_US',
            'company' => null,
        ]);

        Sanctum::actingAs($admin);

        $create = $this->postJson('/api/admin/templates', [
            'name' => 'Personalized Dynamic Template',
            'channel' => 'email',
            'subject' => '{{#if city=Riyadh}}Riyadh Offer{{else}}Global Offer{{/if}}',
            'html' => '{{#lang ar}}مرحبا {{first_name|عميل}}{{/lang}}{{#lang en}}Hello {{first_name|Customer}}{{/lang}} from {{company|our team}}',
        ])->assertCreated();

        $templateId = (int) $create->json('template.id');

        $this->postJson('/api/admin/templates/'.$templateId.'/render', [
            'lead_ids' => [$leadArabic->id, $leadEnglish->id],
        ])->assertOk()
            ->assertJsonPath('previews.0.lead_id', $leadArabic->id)
            ->assertJsonPath('previews.0.rendered.subject', 'Riyadh Offer')
            ->assertJsonPath('previews.0.rendered.html', 'مرحبا عميل from our team')
            ->assertJsonPath('previews.0.personalization.locale', 'ar-sa')
            ->assertJsonPath('previews.1.lead_id', $leadEnglish->id)
            ->assertJsonPath('previews.1.rendered.subject', 'Global Offer')
            ->assertJsonPath('previews.1.rendered.html', 'Hello Adam from our team')
            ->assertJsonPath('previews.1.personalization.locale', 'en-us');

        $this->postJson('/api/admin/templates/'.$templateId.'/render', [
            'lead_id' => $leadArabic->id,
        ])->assertOk()
            ->assertJsonPath('lead_id', $leadArabic->id)
            ->assertJsonPath('rendered.subject', 'Riyadh Offer')
            ->assertJsonPath('rendered.html', 'مرحبا عميل from our team')
            ->assertJsonPath('personalization.conditions.evaluated', 1)
            ->assertJsonPath('personalization.localization.evaluated', 2)
            ->assertJsonPath('personalization.fallbacks_used.0.key', 'first_name');
    }
}
