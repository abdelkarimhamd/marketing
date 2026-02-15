<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Lead;
use App\Models\Message;
use App\Models\SavedView;
use App\Models\Tag;
use App\Models\Team;
use App\Models\TeamUser;
use App\Models\Tenant;
use App\Models\TenantRole;
use App\Models\User;
use App\Support\PermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdvancedSearchSavedViewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_search_returns_grouped_full_text_results_with_filters(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);
        $owner = User::factory()->sales()->create(['tenant_id' => $tenant->id]);
        $team = Team::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Closers']);

        $hotTag = Tag::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Hot',
            'slug' => 'hot',
            'color' => '#ff0000',
        ]);

        $lead = Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'owner_id' => $owner->id,
            'team_id' => $team->id,
            'first_name' => 'Acme',
            'last_name' => 'Owner',
            'email' => 'acme.owner@example.test',
            'company' => 'Acme Smart',
            'status' => 'contacted',
            'source' => 'website',
            'score' => 88,
        ]);

        DB::table('lead_tag')->insert([
            'tenant_id' => $tenant->id,
            'lead_id' => $lead->id,
            'tag_id' => $hotTag->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $otherLead = Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'owner_id' => null,
            'team_id' => null,
            'first_name' => 'Other',
            'last_name' => 'Lead',
            'email' => 'other@example.test',
            'company' => 'Different Co',
            'status' => 'new',
            'source' => 'import',
            'score' => 12,
        ]);

        $message = Message::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'lead_id' => $lead->id,
            'direction' => 'outbound',
            'status' => 'queued',
            'channel' => 'email',
            'thread_key' => 'thread-acme-1',
            'to' => $lead->email,
            'from' => 'sales@tenant.test',
            'subject' => 'Acme proposal',
            'body' => 'Follow-up for Acme implementation.',
        ]);

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'actor_id' => $admin->id,
            'type' => 'lead.followup',
            'subject_type' => Lead::class,
            'subject_id' => $lead->id,
            'description' => 'Called Acme lead.',
        ]);

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'actor_id' => $admin->id,
            'type' => 'message.sent',
            'subject_type' => Message::class,
            'subject_id' => $message->id,
            'description' => 'Acme proposal sent by email.',
        ]);

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'actor_id' => $admin->id,
            'type' => 'lead.note',
            'subject_type' => Lead::class,
            'subject_id' => $otherLead->id,
            'description' => 'Unrelated activity.',
        ]);

        Sanctum::actingAs($admin);

        $query = http_build_query([
            'q' => 'acme',
            'types' => ['leads', 'deals', 'conversations', 'activities'],
            'owner_id' => $owner->id,
            'status' => ['contacted'],
            'tag_ids' => [$hotTag->id],
            'per_type' => 10,
        ]);

        $this->getJson('/api/admin/search?'.$query)
            ->assertOk()
            ->assertJsonPath('counts.leads', 1)
            ->assertJsonPath('counts.deals', 1)
            ->assertJsonPath('counts.conversations', 1)
            ->assertJsonPath('counts.activities', 2)
            ->assertJsonPath('results.leads.0.email', 'acme.owner@example.test')
            ->assertJsonPath('results.deals.0.status', 'contacted')
            ->assertJsonPath('results.conversations.0.subject', 'Acme proposal');
    }

    public function test_saved_views_respect_user_team_visibility_and_edit_permissions(): void
    {
        $tenant = Tenant::factory()->create();
        $salesA = User::factory()->sales()->create(['tenant_id' => $tenant->id, 'email' => 'a@example.test']);
        $salesB = User::factory()->sales()->create(['tenant_id' => $tenant->id, 'email' => 'b@example.test']);
        $salesC = User::factory()->sales()->create(['tenant_id' => $tenant->id, 'email' => 'c@example.test']);

        $teamA = Team::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Alpha']);
        $teamB = Team::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Beta']);

        TeamUser::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'team_id' => $teamA->id,
            'user_id' => $salesA->id,
            'role' => 'member',
            'is_primary' => true,
        ]);

        TeamUser::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'team_id' => $teamA->id,
            'user_id' => $salesB->id,
            'role' => 'member',
            'is_primary' => true,
        ]);

        TeamUser::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'team_id' => $teamB->id,
            'user_id' => $salesC->id,
            'role' => 'member',
            'is_primary' => true,
        ]);

        $viewerRole = TenantRole::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Lead Viewer',
            'slug' => 'lead-viewer',
            'permissions' => $this->matrixWith(['leads.view']),
        ]);

        foreach ([$salesA, $salesB, $salesC] as $user) {
            $user->tenantRoles()->attach($viewerRole->id, ['tenant_id' => $tenant->id]);
        }

        Sanctum::actingAs($salesA);

        $userViewResponse = $this->postJson('/api/admin/saved-views', [
            'name' => 'My Hot Leads',
            'scope' => 'user',
            'query' => 'hot',
            'filters' => [
                'status' => ['contacted'],
                'min_score' => 70,
            ],
        ])->assertCreated();

        $userViewId = (int) $userViewResponse->json('saved_view.id');

        $teamViewResponse = $this->postJson('/api/admin/saved-views', [
            'name' => 'No Response 3 Days',
            'scope' => 'team',
            'team_id' => $teamA->id,
            'query' => '',
            'filters' => [
                'no_response_days' => 3,
                'status' => ['contacted'],
            ],
        ])->assertCreated();

        $teamViewId = (int) $teamViewResponse->json('saved_view.id');

        Sanctum::actingAs($salesB);

        $salesBList = $this->getJson('/api/admin/saved-views')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $salesBList);
        $this->assertSame($teamViewId, (int) data_get($salesBList, '0.id'));
        $this->assertFalse((bool) data_get($salesBList, '0.can_edit'));

        $this->patchJson('/api/admin/saved-views/'.$teamViewId, [
            'name' => 'Team Edit Attempt',
        ])->assertForbidden();

        Sanctum::actingAs($salesC);

        $salesCList = $this->getJson('/api/admin/saved-views')
            ->assertOk()
            ->json('data');

        $this->assertCount(0, $salesCList);

        $this->patchJson('/api/admin/saved-views/'.$teamViewId, [
            'name' => 'External Edit Attempt',
        ])->assertForbidden();

        Sanctum::actingAs($salesA);

        $this->patchJson('/api/admin/saved-views/'.$teamViewId, [
            'name' => 'No Response 3+ Days',
        ])->assertOk()
            ->assertJsonPath('saved_view.name', 'No Response 3+ Days');

        $this->deleteJson('/api/admin/saved-views/'.$userViewId)
            ->assertOk();

        $this->assertDatabaseMissing('saved_views', [
            'id' => $userViewId,
        ]);

        $teamSavedView = SavedView::query()->withoutTenancy()->findOrFail($teamViewId);
        $this->assertSame('team', $teamSavedView->scope);
        $this->assertSame($teamA->id, (int) $teamSavedView->team_id);
    }

    /**
     * @param list<string> $permissions
     * @return array<string, array<string, bool>>
     */
    private function matrixWith(array $permissions): array
    {
        return app(PermissionMatrix::class)->matrixFromPermissions($permissions);
    }
}
