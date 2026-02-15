<?php

namespace Tests\Feature;

use App\Jobs\LaunchCampaignJob;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Segment;
use App\Models\Template;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HighRiskApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_launch_requires_approval_and_executes_after_checker_approval(): void
    {
        $actions = config('high_risk.actions', []);
        $actions['campaign.mass_send'] = [
            'enabled' => true,
            'audience_threshold' => 1,
            'required_approvals' => 1,
        ];
        config(['high_risk.actions' => $actions]);

        $tenant = Tenant::factory()->create();
        $maker = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);
        $checker = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);

        $segment = Segment::factory()->create([
            'tenant_id' => $tenant->id,
            'rules_json' => null,
        ]);

        $template = Template::factory()->create([
            'tenant_id' => $tenant->id,
            'channel' => 'email',
        ]);

        $campaign = Campaign::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'name' => 'High Risk Campaign',
            'slug' => 'high-risk-campaign',
            'channel' => 'email',
            'campaign_type' => Campaign::TYPE_BROADCAST,
            'status' => Campaign::STATUS_DRAFT,
            'settings' => [],
            'metrics' => [],
        ]);

        Lead::factory()->count(2)->create([
            'tenant_id' => $tenant->id,
            'email_consent' => true,
        ]);

        Queue::fake();
        Sanctum::actingAs($maker);

        $pendingResponse = $this->postJson('/api/admin/campaigns/'.$campaign->id.'/launch')
            ->assertStatus(202)
            ->assertJsonPath('requires_approval', true);

        $approvalId = (int) $pendingResponse->json('approval.id');

        $this->assertDatabaseHas('high_risk_approvals', [
            'id' => $approvalId,
            'tenant_id' => $tenant->id,
            'action' => 'campaign.mass_send',
            'status' => 'pending',
        ]);

        Queue::assertNothingPushed();
        $this->assertSame(Campaign::STATUS_DRAFT, $campaign->refresh()->status);

        Sanctum::actingAs($checker);
        $this->postJson('/api/admin/approvals/'.$approvalId.'/review', [
            'approve' => true,
        ])->assertOk()
            ->assertJsonPath('approval.status', 'approved');

        Sanctum::actingAs($maker);
        $this->postJson('/api/admin/campaigns/'.$campaign->id.'/launch', [
            'approval_id' => $approvalId,
        ])->assertOk()
            ->assertJsonPath('approval_id', $approvalId);

        Queue::assertPushed(LaunchCampaignJob::class, 1);
        $this->assertDatabaseHas('high_risk_approvals', [
            'id' => $approvalId,
            'status' => 'executed',
            'executed_by' => $maker->id,
        ]);
    }

    public function test_export_requires_approval_and_executes_after_checker_approval(): void
    {
        $actions = config('high_risk.actions', []);
        $actions['leads.export'] = [
            'enabled' => true,
            'row_threshold' => 1,
            'required_approvals' => 1,
        ];
        config(['high_risk.actions' => $actions]);

        $tenant = Tenant::factory()->create();
        $maker = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);
        $checker = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);

        Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'exportable@example.test',
        ]);

        Sanctum::actingAs($maker);

        $pendingResponse = $this->postJson('/api/admin/exports/run', [
            'type' => 'leads',
        ])->assertStatus(202)
            ->assertJsonPath('requires_approval', true);

        $approvalId = (int) $pendingResponse->json('approval.id');

        Sanctum::actingAs($checker);
        $this->postJson('/api/admin/approvals/'.$approvalId.'/review', [
            'approve' => true,
        ])->assertOk()
            ->assertJsonPath('approval.status', 'approved');

        Sanctum::actingAs($maker);
        $executeResponse = $this->postJson('/api/admin/exports/run', [
            'type' => 'leads',
            'approval_id' => $approvalId,
        ])->assertCreated()
            ->assertJsonPath('approval_id', $approvalId);

        $exportJobId = (int) $executeResponse->json('job.id');

        $this->assertDatabaseHas('export_jobs', [
            'id' => $exportJobId,
            'tenant_id' => $tenant->id,
            'type' => 'leads',
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('high_risk_approvals', [
            'id' => $approvalId,
            'status' => 'executed',
            'executed_by' => $maker->id,
        ]);
    }

    public function test_lead_delete_requires_approval_and_executes_after_checker_approval(): void
    {
        $actions = config('high_risk.actions', []);
        $actions['lead.delete'] = [
            'enabled' => true,
            'required_approvals' => 1,
        ];
        config(['high_risk.actions' => $actions]);

        $tenant = Tenant::factory()->create();
        $maker = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);
        $checker = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);

        $lead = Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'delete-me@example.test',
        ]);

        Sanctum::actingAs($maker);

        $pendingResponse = $this->deleteJson('/api/admin/leads/'.$lead->id)
            ->assertStatus(202)
            ->assertJsonPath('requires_approval', true);

        $approvalId = (int) $pendingResponse->json('approval.id');

        Sanctum::actingAs($checker);
        $this->postJson('/api/admin/approvals/'.$approvalId.'/review', [
            'approve' => true,
        ])->assertOk()
            ->assertJsonPath('approval.status', 'approved');

        Sanctum::actingAs($maker);
        $this->deleteJson('/api/admin/leads/'.$lead->id, [
            'approval_id' => $approvalId,
        ])->assertOk()
            ->assertJsonPath('approval_id', $approvalId);

        $this->assertSoftDeleted('leads', ['id' => $lead->id]);
        $this->assertDatabaseHas('high_risk_approvals', [
            'id' => $approvalId,
            'status' => 'executed',
            'executed_by' => $maker->id,
        ]);
    }

    public function test_lead_merge_requires_approval_and_reassigns_records_after_execution(): void
    {
        $actions = config('high_risk.actions', []);
        $actions['lead.merge'] = [
            'enabled' => true,
            'required_approvals' => 1,
        ];
        config(['high_risk.actions' => $actions]);

        $tenant = Tenant::factory()->create();
        $maker = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);
        $checker = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);

        $source = Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'source@example.test',
            'phone' => '+966500000001',
        ]);

        $target = Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'target@example.test',
            'phone' => null,
        ]);

        $message = Message::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'lead_id' => $source->id,
            'direction' => 'outbound',
            'status' => 'queued',
            'channel' => 'email',
            'to' => $source->email,
            'from' => 'noreply@example.test',
            'subject' => 'Merge Test',
            'body' => 'Body',
        ]);

        Sanctum::actingAs($maker);
        $pendingResponse = $this->postJson('/api/admin/leads/merge', [
            'source_lead_id' => $source->id,
            'target_lead_id' => $target->id,
        ])->assertStatus(202)
            ->assertJsonPath('requires_approval', true);

        $approvalId = (int) $pendingResponse->json('approval.id');

        Sanctum::actingAs($checker);
        $this->postJson('/api/admin/approvals/'.$approvalId.'/review', [
            'approve' => true,
        ])->assertOk()
            ->assertJsonPath('approval.status', 'approved');

        Sanctum::actingAs($maker);
        $this->postJson('/api/admin/leads/merge', [
            'source_lead_id' => $source->id,
            'target_lead_id' => $target->id,
            'approval_id' => $approvalId,
        ])->assertOk()
            ->assertJsonPath('lead.id', $target->id)
            ->assertJsonPath('lead.phone', '+966500000001')
            ->assertJsonPath('merged_from_id', $source->id)
            ->assertJsonPath('approval_id', $approvalId);

        $this->assertSoftDeleted('leads', ['id' => $source->id]);
        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'lead_id' => $target->id,
        ]);
        $this->assertDatabaseHas('high_risk_approvals', [
            'id' => $approvalId,
            'status' => 'executed',
            'executed_by' => $maker->id,
        ]);
    }
}
