<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Playbook;
use App\Models\Tenant;
use App\Models\TenantRole;
use App\Models\User;
use App\Support\PermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlaybookKnowledgeBaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_can_manage_playbooks_and_get_contextual_suggestions(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);

        $lead = Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Nora',
            'email' => 'nora.clinic@example.test',
            'status' => 'contacted',
            'interest' => 'clinic growth',
            'service' => 'medical automation',
        ]);

        Sanctum::actingAs($admin);

        $create = $this->postJson('/api/admin/playbooks', [
            'name' => 'Clinic Follow-up',
            'industry' => 'clinic',
            'stage' => 'contacted',
            'channel' => 'email',
            'scripts' => [
                'Qualify monthly appointment volume.',
                'Confirm current reminder process.',
            ],
            'objections' => [
                [
                    'objection' => 'Budget is limited.',
                    'response' => 'Start with one branch and expand after ROI.',
                ],
            ],
            'templates' => [
                [
                    'title' => 'Clinic Follow-up Email',
                    'channel' => 'email',
                    'content' => 'Hi {{first_name}}, can we review your current no-show rate this week?',
                ],
            ],
        ])->assertCreated();

        $playbookId = (int) $create->json('playbook.id');

        $this->getJson('/api/admin/playbooks?industry=clinic')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $playbookId);

        $this->patchJson('/api/admin/playbooks/'.$playbookId, [
            'name' => 'Clinic Follow-up Updated',
            'scripts' => [
                'Qualify monthly appointment volume.',
                'Confirm reminder workflow owner.',
            ],
        ])->assertOk()
            ->assertJsonPath('playbook.name', 'Clinic Follow-up Updated');

        $this->getJson('/api/admin/playbooks/suggestions?lead_id='.$lead->id.'&stage=contacted&channel=email&q=budget')
            ->assertOk()
            ->assertJsonPath('context.lead_id', $lead->id)
            ->assertJsonPath('suggestions.0.playbook_id', $playbookId)
            ->assertJsonPath('suggestions.0.objections.0.objection', 'Budget is limited.');

        $this->deleteJson('/api/admin/playbooks/'.$playbookId)
            ->assertOk();

        $this->assertDatabaseMissing('playbooks', [
            'id' => $playbookId,
        ]);
    }

    public function test_bootstrap_creates_industry_starters_and_respects_overwrite_flag(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);

        Sanctum::actingAs($admin);

        $first = $this->postJson('/api/admin/playbooks/bootstrap', [
            'industries' => ['clinic', 'restaurant'],
        ])->assertOk();

        $this->assertSame(2, (int) $first->json('result.created'));
        $this->assertSame(0, (int) $first->json('result.updated'));

        $second = $this->postJson('/api/admin/playbooks/bootstrap', [
            'industries' => ['clinic', 'restaurant'],
        ])->assertOk();

        $this->assertSame(0, (int) $second->json('result.created'));
        $this->assertSame(2, (int) $second->json('result.skipped'));

        $third = $this->postJson('/api/admin/playbooks/bootstrap', [
            'industries' => ['clinic', 'restaurant'],
            'overwrite' => true,
        ])->assertOk();

        $this->assertSame(2, (int) $third->json('result.updated'));
    }

    public function test_sales_user_with_view_and_suggest_permissions_cannot_create_playbook(): void
    {
        $tenant = Tenant::factory()->create();
        $sales = User::factory()->sales()->create(['tenant_id' => $tenant->id]);

        $role = TenantRole::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Playbook Consumer',
            'slug' => 'playbook-consumer',
            'permissions' => $this->matrixWith([
                'leads.view',
                'playbooks.view',
                'playbooks.suggest',
            ]),
        ]);

        $sales->tenantRoles()->attach($role->id, ['tenant_id' => $tenant->id]);

        $playbook = Playbook::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Restaurant Objections',
            'slug' => 'restaurant-objections',
            'industry' => 'restaurant',
            'stage' => 'proposal',
            'channel' => 'email',
            'is_active' => true,
            'scripts' => ['Confirm guest count and event date.'],
            'objections' => [],
            'templates' => [],
            'settings' => [],
        ]);

        $lead = Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => 'proposal',
            'interest' => 'restaurant catering',
        ]);

        Sanctum::actingAs($sales);

        $this->getJson('/api/admin/playbooks')
            ->assertOk()
            ->assertJsonPath('data.0.id', $playbook->id);

        $this->getJson('/api/admin/playbooks/suggestions?lead_id='.$lead->id.'&stage=proposal&channel=email')
            ->assertOk()
            ->assertJsonPath('suggestions.0.playbook_id', $playbook->id);

        $this->postJson('/api/admin/playbooks', [
            'name' => 'Unauthorized Create',
            'industry' => 'restaurant',
        ])->assertForbidden();
    }

    /**
     * Build a normalized permission matrix from flat permission keys.
     *
     * @param list<string> $permissions
     * @return array<string, array<string, bool>>
     */
    private function matrixWith(array $permissions): array
    {
        return app(PermissionMatrix::class)->matrixFromPermissions($permissions);
    }
}
