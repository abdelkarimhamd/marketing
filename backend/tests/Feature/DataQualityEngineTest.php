<?php

namespace Tests\Feature;

use App\Models\DataQualityRun;
use App\Models\Lead;
use App\Models\MergeSuggestion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DataQualityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DataQualityEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_start_data_quality_run(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/data-quality/runs', [
            'run_type' => 'full',
        ]);

        $response->assertAccepted()
            ->assertJsonPath('run.tenant_id', $tenant->id)
            ->assertJsonPath('run.run_type', 'full');

        $this->assertDatabaseHas('data_quality_runs', [
            'tenant_id' => $tenant->id,
            'run_type' => 'full',
        ]);
    }

    public function test_execute_run_creates_merge_suggestions_only_within_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        Lead::factory()->create([
            'tenant_id' => $tenantA->id,
            'email' => 'dup@example.test',
            'phone' => '+1555000001',
        ]);

        Lead::factory()->create([
            'tenant_id' => $tenantA->id,
            'email' => 'dup@example.test',
            'phone' => '+1555000002',
        ]);

        Lead::factory()->create([
            'tenant_id' => $tenantB->id,
            'email' => 'dup@example.test',
            'phone' => '+1555999999',
        ]);

        $run = DataQualityRun::query()->withoutTenancy()->create([
            'tenant_id' => $tenantA->id,
            'run_type' => 'full',
            'status' => 'queued',
        ]);

        app(DataQualityService::class)->executeRun($run);

        $this->assertDatabaseHas('data_quality_runs', [
            'id' => $run->id,
            'tenant_id' => $tenantA->id,
            'status' => 'completed',
        ]);

        $this->assertDatabaseCount('merge_suggestions', 1);
        $this->assertDatabaseHas('merge_suggestions', [
            'tenant_id' => $tenantA->id,
            'reason' => 'exact_email',
            'status' => 'pending',
        ]);
    }

    public function test_reviewing_merge_suggestion_as_merged_soft_deletes_secondary_lead(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);

        $leadA = Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'same@example.test',
            'phone' => '+1555000101',
        ]);

        $leadB = Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => null,
            'phone' => '+1555000102',
        ]);

        $suggestion = MergeSuggestion::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'candidate_a_id' => min($leadA->id, $leadB->id),
            'candidate_b_id' => max($leadA->id, $leadB->id),
            'reason' => 'exact_phone',
            'confidence' => 95,
            'status' => 'pending',
            'meta' => ['field' => 'phone'],
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/data-quality/merge-suggestions/{$suggestion->id}/review", [
            'status' => 'merged',
        ])->assertOk()
            ->assertJsonPath('suggestion.status', 'merged');

        $this->assertDatabaseHas('merge_suggestions', [
            'id' => $suggestion->id,
            'tenant_id' => $tenant->id,
            'status' => 'merged',
            'reviewed_by' => $admin->id,
        ]);

        $secondaryLeadId = max($leadA->id, $leadB->id);
        $this->assertSoftDeleted('leads', ['id' => $secondaryLeadId]);
    }
}
