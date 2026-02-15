<?php

namespace Tests\Feature;

use App\Models\AssignmentRule;
use App\Models\Lead;
use App\Models\RealtimeEvent;
use App\Models\Tag;
use App\Models\Team;
use App\Models\TeamUser;
use App\Models\Tenant;
use App\Models\User;
use App\Services\LeadAssignmentService;
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

    public function test_rules_engine_applies_advanced_conditions_and_multi_actions(): void
    {
        config(['enrichment.email.check_mx' => false]);

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);
        $owner = User::factory()->sales()->create(['tenant_id' => $tenant->id]);

        AssignmentRule::factory()->create([
            'tenant_id' => $tenant->id,
            'strategy' => AssignmentRule::STRATEGY_RULES_ENGINE,
            'priority' => 1,
            'is_active' => true,
            'conditions' => [
                'operator' => 'all',
                'sources' => ['website'],
                'utm' => [
                    'source' => ['google'],
                    'medium' => ['cpc'],
                ],
                'score' => ['min' => 60],
                'geo' => ['countries' => ['sa']],
                'working_hours' => [
                    'timezone' => 'UTC',
                    'start' => '00:00',
                    'end' => '23:59',
                    'days' => [1, 2, 3, 4, 5, 6, 7],
                ],
            ],
            'settings' => [
                'actions' => [
                    [
                        'type' => AssignmentRule::ACTION_ASSIGN,
                        'owner_id' => $owner->id,
                    ],
                    [
                        'type' => AssignmentRule::ACTION_ADD_TAGS,
                        'tags' => ['routed', 'vip'],
                    ],
                    [
                        'type' => AssignmentRule::ACTION_CREATE_DEAL,
                        'status' => 'qualified',
                        'pipeline' => 'default',
                        'stage' => 'new',
                    ],
                    [
                        'type' => AssignmentRule::ACTION_START_AUTOMATION,
                        'automation' => 'welcome_flow',
                        'payload' => ['step' => 'start'],
                    ],
                    [
                        'type' => AssignmentRule::ACTION_NOTIFY_CHANNEL,
                        'channel' => 'slack:sales',
                        'message' => 'Lead {{email}} matched {{rule_name}}',
                    ],
                ],
            ],
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/leads', [
            'first_name' => 'Routing',
            'email' => 'routing@acme.sa',
            'source' => 'website',
            'score' => 80,
            'country_code' => 'SA',
            'meta' => [
                'utm' => [
                    'source' => 'google',
                    'medium' => 'cpc',
                    'campaign' => 'spring-2026',
                ],
            ],
            'auto_assign' => true,
        ])->assertCreated();

        $lead = Lead::query()->withoutTenancy()->where('email', 'routing@acme.sa')->firstOrFail();

        $this->assertSame($owner->id, $lead->owner_id);
        $this->assertSame('qualified', $lead->status);
        $this->assertSame('welcome_flow', data_get($lead->meta, 'routing.automations.0.automation'));

        $routedTag = Tag::query()->withoutTenancy()->where('tenant_id', $tenant->id)->where('slug', 'routed')->firstOrFail();
        $vipTag = Tag::query()->withoutTenancy()->where('tenant_id', $tenant->id)->where('slug', 'vip')->firstOrFail();

        $this->assertDatabaseHas('lead_tag', ['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'tag_id' => $routedTag->id]);
        $this->assertDatabaseHas('lead_tag', ['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'tag_id' => $vipTag->id]);

        $events = RealtimeEvent::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->where('subject_type', Lead::class)
            ->where('subject_id', $lead->id)
            ->pluck('event_name')
            ->all();

        $this->assertContains('deal.created', $events);
        $this->assertContains('automation.started', $events);
        $this->assertContains('routing.notify.channel', $events);
        $this->assertContains('lead.routing.rule_matched', $events);
    }

    public function test_rules_engine_working_hours_condition_can_block_rule_match(): void
    {
        config(['enrichment.email.check_mx' => false]);

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);
        $owner = User::factory()->sales()->create(['tenant_id' => $tenant->id]);
        $today = (int) now('UTC')->isoWeekday();
        $differentDay = $today === 1 ? 2 : 1;

        AssignmentRule::factory()->create([
            'tenant_id' => $tenant->id,
            'strategy' => AssignmentRule::STRATEGY_RULES_ENGINE,
            'priority' => 1,
            'is_active' => true,
            'conditions' => [
                'sources' => ['website'],
                'working_hours' => [
                    'timezone' => 'UTC',
                    'start' => '00:00',
                    'end' => '23:59',
                    'days' => [$differentDay],
                ],
            ],
            'settings' => [
                'actions' => [
                    [
                        'type' => AssignmentRule::ACTION_ASSIGN,
                        'owner_id' => $owner->id,
                    ],
                ],
            ],
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/leads', [
            'email' => 'offhours@acme.sa',
            'source' => 'website',
            'auto_assign' => true,
        ])->assertCreated();

        $lead = Lead::query()->withoutTenancy()->where('email', 'offhours@acme.sa')->firstOrFail();

        $this->assertNull($lead->owner_id);
    }

    public function test_round_robin_skips_unavailable_agents_using_schedule_and_holidays(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'availability-tenant']);
        $team = Team::factory()->create(['tenant_id' => $tenant->id]);
        $offlineUser = User::factory()->sales()->create(['tenant_id' => $tenant->id]);
        $availableUser = User::factory()->sales()->create(['tenant_id' => $tenant->id]);

        $offlineUser->forceFill([
            'settings' => [
                'assignment' => [
                    'availability' => [
                        'timezone' => 'UTC',
                        'working_hours' => [
                            'days' => [1, 2, 3, 4, 5, 6, 7],
                            'start' => '00:00',
                            'end' => '23:59',
                        ],
                        'holidays' => [now('UTC')->toDateString()],
                        'is_online' => true,
                        'max_active_leads' => 50,
                    ],
                ],
            ],
        ])->save();

        TeamUser::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'team_id' => $team->id,
            'user_id' => $offlineUser->id,
            'role' => 'member',
            'is_primary' => true,
        ]);

        TeamUser::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'team_id' => $team->id,
            'user_id' => $availableUser->id,
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
            'email' => 'availability-check@example.test',
        ])->assertCreated();

        $lead = Lead::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->where('email', 'availability-check@example.test')
            ->firstOrFail();

        $this->assertSame($availableUser->id, $lead->owner_id);
    }

    public function test_round_robin_respects_max_active_leads_capacity(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'capacity-tenant']);
        $team = Team::factory()->create(['tenant_id' => $tenant->id]);
        $atCapacityUser = User::factory()->sales()->create(['tenant_id' => $tenant->id]);
        $availableUser = User::factory()->sales()->create(['tenant_id' => $tenant->id]);

        $atCapacityUser->forceFill([
            'settings' => [
                'assignment' => [
                    'availability' => [
                        'timezone' => 'UTC',
                        'working_hours' => [
                            'days' => [1, 2, 3, 4, 5, 6, 7],
                            'start' => '00:00',
                            'end' => '23:59',
                        ],
                        'is_online' => true,
                        'max_active_leads' => 1,
                    ],
                ],
            ],
        ])->save();

        TeamUser::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'team_id' => $team->id,
            'user_id' => $atCapacityUser->id,
            'role' => 'member',
            'is_primary' => true,
        ]);

        TeamUser::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'team_id' => $team->id,
            'user_id' => $availableUser->id,
            'role' => 'member',
            'is_primary' => false,
        ]);

        Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'owner_id' => $atCapacityUser->id,
            'status' => 'new',
        ]);

        AssignmentRule::factory()->create([
            'tenant_id' => $tenant->id,
            'team_id' => $team->id,
            'strategy' => AssignmentRule::STRATEGY_ROUND_ROBIN,
            'priority' => 1,
            'auto_assign_on_intake' => true,
            'fallback_owner_id' => null,
            'conditions' => [],
        ]);

        $this->withHeader('X-Tenant-Slug', $tenant->slug)->postJson('/api/public/leads', [
            'email' => 'capacity-check@example.test',
        ])->assertCreated();

        $lead = Lead::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->where('email', 'capacity-check@example.test')
            ->firstOrFail();

        $this->assertSame($availableUser->id, $lead->owner_id);
    }

    public function test_assign_action_auto_reassigns_when_current_owner_is_unavailable(): void
    {
        $tenant = Tenant::factory()->create();
        $team = Team::factory()->create(['tenant_id' => $tenant->id]);
        $offlineOwner = User::factory()->sales()->create(['tenant_id' => $tenant->id]);
        $backupOwner = User::factory()->sales()->create(['tenant_id' => $tenant->id]);

        $offlineOwner->forceFill([
            'settings' => [
                'assignment' => [
                    'availability' => [
                        'is_online' => false,
                        'status' => 'offline',
                    ],
                ],
            ],
        ])->save();

        TeamUser::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'team_id' => $team->id,
            'user_id' => $offlineOwner->id,
            'role' => 'member',
            'is_primary' => true,
        ]);

        TeamUser::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'team_id' => $team->id,
            'user_id' => $backupOwner->id,
            'role' => 'member',
            'is_primary' => false,
        ]);

        AssignmentRule::factory()->create([
            'tenant_id' => $tenant->id,
            'team_id' => $team->id,
            'strategy' => AssignmentRule::STRATEGY_RULES_ENGINE,
            'priority' => 1,
            'is_active' => true,
            'conditions' => [],
            'settings' => [
                'actions' => [
                    [
                        'type' => AssignmentRule::ACTION_ASSIGN,
                        'mode' => 'round_robin',
                        'team_id' => $team->id,
                        'reassign_if_unavailable' => true,
                    ],
                ],
            ],
        ]);

        $lead = Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'team_id' => $team->id,
            'owner_id' => $offlineOwner->id,
            'status' => 'new',
            'source' => 'website',
        ]);

        app(LeadAssignmentService::class)->assignLead($lead, 'manual');

        $lead->refresh();

        $this->assertSame($backupOwner->id, $lead->owner_id);
    }
}
