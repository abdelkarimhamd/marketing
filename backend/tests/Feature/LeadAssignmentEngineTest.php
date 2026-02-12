<?php

namespace Tests\Feature;

use App\Models\AssignmentRule;
use App\Models\Lead;
use App\Models\Team;
use App\Models\TeamUser;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeadAssignmentEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_round_robin_by_team_on_public_intake(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'rr-tenant']);
        $team = Team::factory()->create(['tenant_id' => $tenant->id]);
        $userA = User::factory()->sales()->create(['tenant_id' => $tenant->id]);
        $userB = User::factory()->sales()->create(['tenant_id' => $tenant->id]);

        TeamUser::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'team_id' => $team->id,
            'user_id' => $userA->id,
            'role' => 'member',
            'is_primary' => true,
        ]);

        TeamUser::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'team_id' => $team->id,
            'user_id' => $userB->id,
            'role' => 'member',
            'is_primary' => false,
        ]);

        AssignmentRule::factory()->create([
            'tenant_id' => $tenant->id,
            'team_id' => $team->id,
            'strategy' => AssignmentRule::STRATEGY_ROUND_ROBIN,
            'priority' => 1,
            'auto_assign_on_intake' => true,
            'auto_assign_on_import' => true,
            'fallback_owner_id' => null,
            'conditions' => [],
        ]);

        $this->withHeader('X-Tenant-Slug', $tenant->slug)->postJson('/api/public/leads', [
            'email' => 'rr-1@example.test',
        ])->assertCreated();

        $this->withHeader('X-Tenant-Slug', $tenant->slug)->postJson('/api/public/leads', [
            'email' => 'rr-2@example.test',
        ])->assertCreated();

        $this->withHeader('X-Tenant-Slug', $tenant->slug)->postJson('/api/public/leads', [
            'email' => 'rr-3@example.test',
        ])->assertCreated();

        $owners = Lead::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->orderBy('id')
            ->pluck('owner_id')
            ->all();

        $this->assertSame([$userA->id, $userB->id, $userA->id], $owners);
    }

    public function test_city_rule_assigns_fallback_owner_on_intake(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'city-tenant']);
        $fallbackOwner = User::factory()->sales()->create(['tenant_id' => $tenant->id]);

        AssignmentRule::factory()->create([
            'tenant_id' => $tenant->id,
            'strategy' => AssignmentRule::STRATEGY_CITY,
            'priority' => 1,
            'fallback_owner_id' => $fallbackOwner->id,
            'auto_assign_on_intake' => true,
            'conditions' => [
                'cities' => ['riyadh', 'jeddah'],
            ],
        ]);

        $this->withHeader('X-Tenant-Slug', $tenant->slug)->postJson('/api/public/leads', [
            'email' => 'city-rule@example.test',
            'city' => 'Riyadh',
        ])->assertCreated();

        $lead = Lead::query()->withoutTenancy()->where('tenant_id', $tenant->id)->first();

        $this->assertNotNull($lead);
        $this->assertSame($fallbackOwner->id, $lead->owner_id);
    }

    public function test_interest_service_rule_auto_assigns_on_import_when_enabled(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);
        $fallbackOwner = User::factory()->sales()->create(['tenant_id' => $tenant->id]);

        AssignmentRule::factory()->create([
            'tenant_id' => $tenant->id,
            'strategy' => AssignmentRule::STRATEGY_INTEREST_SERVICE,
            'priority' => 1,
            'fallback_owner_id' => $fallbackOwner->id,
            'auto_assign_on_import' => true,
            'conditions' => [
                'interests' => ['solar'],
                'services' => ['implementation'],
            ],
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/leads/import', [
            'auto_assign' => true,
            'leads' => [
                [
                    'email' => 'import-match@example.test',
                    'interest' => 'solar',
                    'service' => 'implementation',
                ],
                [
                    'email' => 'import-no-match@example.test',
                    'interest' => 'crm',
                    'service' => 'consulting',
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('created_count', 2)
            ->assertJsonPath('assigned_count', 1);

        $matched = Lead::query()->withoutTenancy()->where('email', 'import-match@example.test')->firstOrFail();
        $unmatched = Lead::query()->withoutTenancy()->where('email', 'import-no-match@example.test')->firstOrFail();

        $this->assertSame($fallbackOwner->id, $matched->owner_id);
        $this->assertNull($unmatched->owner_id);
    }
}
