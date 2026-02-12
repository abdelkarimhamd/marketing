<?php

namespace Tests\Feature;

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
}
