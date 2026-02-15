<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Lead;
use App\Models\Tag;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeadsAdminModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_lead_index_supports_filters_search_and_pagination(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);

        Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Acme',
            'last_name' => 'Lead',
            'company' => 'Acme Corp',
            'status' => 'new',
            'city' => 'Riyadh',
        ]);

        Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Other',
            'last_name' => 'Lead',
            'company' => 'Other Company',
            'status' => 'won',
            'city' => 'Jeddah',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/leads?search=Acme&status=new&city=Riyadh&per_page=1')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.company', 'Acme Corp');
    }

    public function test_bulk_actions_assign_tag_and_status(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);
        $owner = User::factory()->sales()->create(['tenant_id' => $tenant->id]);
        $team = Team::factory()->create(['tenant_id' => $tenant->id]);

        $leadA = Lead::factory()->create(['tenant_id' => $tenant->id, 'owner_id' => null, 'team_id' => null]);
        $leadB = Lead::factory()->create(['tenant_id' => $tenant->id, 'owner_id' => null, 'team_id' => null]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/leads/bulk', [
            'action' => 'assign',
            'lead_ids' => [$leadA->id, $leadB->id],
            'owner_id' => $owner->id,
            'team_id' => $team->id,
        ])->assertOk();

        $this->assertDatabaseHas('leads', ['id' => $leadA->id, 'owner_id' => $owner->id, 'team_id' => $team->id]);
        $this->assertDatabaseHas('leads', ['id' => $leadB->id, 'owner_id' => $owner->id, 'team_id' => $team->id]);

        $this->postJson('/api/admin/leads/bulk', [
            'action' => 'tag',
            'lead_ids' => [$leadA->id, $leadB->id],
            'tags' => ['vip', 'website'],
        ])->assertOk();

        $vipTag = Tag::query()->withoutTenancy()->where('tenant_id', $tenant->id)->where('slug', 'vip')->first();
        $websiteTag = Tag::query()->withoutTenancy()->where('tenant_id', $tenant->id)->where('slug', 'website')->first();

        $this->assertNotNull($vipTag);
        $this->assertNotNull($websiteTag);
        $this->assertDatabaseHas('lead_tag', ['tenant_id' => $tenant->id, 'lead_id' => $leadA->id, 'tag_id' => $vipTag->id]);
        $this->assertDatabaseHas('lead_tag', ['tenant_id' => $tenant->id, 'lead_id' => $leadB->id, 'tag_id' => $websiteTag->id]);

        $this->postJson('/api/admin/leads/bulk', [
            'action' => 'status',
            'lead_ids' => [$leadA->id, $leadB->id],
            'status' => 'contacted',
        ])->assertOk();

        $this->assertDatabaseHas('leads', ['id' => $leadA->id, 'status' => 'contacted']);
        $this->assertDatabaseHas('leads', ['id' => $leadB->id, 'status' => 'contacted']);
    }

    public function test_admin_lead_store_auto_enriches_company_phone_country_and_carrier(): void
    {
        config([
            'enrichment.email.check_mx' => false,
        ]);

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/leads', [
            'first_name' => 'Nora',
            'email' => 'owner@acme.sa',
            'phone' => '050 123 4567',
            'country_code' => 'sa',
        ]);

        $response->assertCreated()
            ->assertJsonPath('lead.email', 'owner@acme.sa')
            ->assertJsonPath('lead.phone', '+966501234567')
            ->assertJsonPath('lead.country_code', 'SA')
            ->assertJsonPath('lead.company', 'Acme');

        $leadId = (int) $response->json('lead.id');
        $lead = Lead::query()->withoutTenancy()->findOrFail($leadId);

        $this->assertSame('stc', data_get($lead->meta, 'enrichment.phone.carrier'));
        $this->assertSame('SA', data_get($lead->meta, 'enrichment.phone.country_code'));
    }

    public function test_import_requires_valid_phone_when_email_is_missing(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/leads/import', [
            'leads' => [
                [
                    'phone' => 'invalid-number',
                ],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_assignment_rules_crud_is_available_for_admins(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);
        $sales = User::factory()->sales()->create(['tenant_id' => $tenant->id]);
        $team = Team::factory()->create(['tenant_id' => $tenant->id]);

        Sanctum::actingAs($admin);

        $createResponse = $this->postJson('/api/admin/assignment-rules', [
            'name' => 'City Rule',
            'team_id' => $team->id,
            'fallback_owner_id' => $sales->id,
            'strategy' => 'city',
            'priority' => 50,
            'conditions' => [
                'cities' => ['riyadh', 'jeddah'],
            ],
            'auto_assign_on_intake' => true,
            'auto_assign_on_import' => false,
        ])->assertCreated();

        $ruleId = $createResponse->json('rule.id');

        $this->assertDatabaseHas('assignment_rules', [
            'id' => $ruleId,
            'tenant_id' => $tenant->id,
            'strategy' => 'city',
            'auto_assign_on_intake' => 1,
            'auto_assign_on_import' => 0,
        ]);

        $this->patchJson('/api/admin/assignment-rules/'.$ruleId, [
            'is_active' => false,
            'priority' => 10,
            'strategy' => 'interest_service',
        ])->assertOk()
            ->assertJsonPath('rule.strategy', 'interest_service')
            ->assertJsonPath('rule.is_active', false);
    }

    public function test_assignment_rule_can_store_actions_for_rules_engine(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);
        $owner = User::factory()->sales()->create(['tenant_id' => $tenant->id]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/assignment-rules', [
            'name' => 'Advanced Routing',
            'strategy' => 'rules_engine',
            'priority' => 1,
            'conditions' => [
                'sources' => ['website'],
            ],
            'actions' => [
                [
                    'type' => 'assign',
                    'owner_id' => $owner->id,
                ],
                [
                    'type' => 'notify_channel',
                    'channel' => 'slack:sales',
                    'message' => 'Lead {{email}} routed.',
                ],
            ],
        ])->assertCreated();

        $ruleId = (int) $response->json('rule.id');

        $this->assertDatabaseHas('assignment_rules', [
            'id' => $ruleId,
            'tenant_id' => $tenant->id,
            'strategy' => 'rules_engine',
        ]);

        $stored = \App\Models\AssignmentRule::query()->withoutTenancy()->findOrFail($ruleId);
        $actions = data_get($stored->settings, 'actions', []);

        $this->assertCount(2, is_array($actions) ? $actions : []);
        $this->assertSame('assign', data_get($actions, '0.type'));
        $this->assertSame('notify_channel', data_get($actions, '1.type'));
    }

    public function test_sales_user_cannot_access_admin_lead_module(): void
    {
        $tenant = Tenant::factory()->create();
        $sales = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::Sales->value,
        ]);

        Sanctum::actingAs($sales);

        $this->getJson('/api/admin/leads')
            ->assertForbidden();
    }
}
