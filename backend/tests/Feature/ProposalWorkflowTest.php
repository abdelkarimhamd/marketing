<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalTemplate;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProposalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_can_generate_send_and_track_proposal_lifecycle(): void
    {
        config()->set('messaging.providers.email', 'mock');
        config()->set('messaging.providers.whatsapp', 'mock');

        $tenant = Tenant::factory()->create([
            'slug' => 'proposal-tenant',
        ]);

        $tenantAdmin = User::factory()->tenantAdmin()->create([
            'tenant_id' => $tenant->id,
        ]);

        $lead = Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Nora',
            'last_name' => 'Ali',
            'email' => 'nora@example.test',
            'phone' => '+15550001111',
            'status' => 'proposal',
            'service' => 'crm',
        ]);

        Sanctum::actingAs($tenantAdmin);

        $templateResponse = $this->postJson('/api/admin/proposal-templates', [
            'name' => 'CRM Quotation',
            'slug' => 'crm-quotation',
            'service' => 'crm',
            'currency' => 'USD',
            'subject' => 'Proposal for {{full_name}}',
            'body_html' => '<h2>Quotation</h2><p>Hello {{full_name}}</p><p>Service {{proposal.service}}</p><p>Amount {{proposal.quote_amount}} {{proposal.currency}}</p>',
            'body_text' => 'Hello {{full_name}} Service {{proposal.service}} Amount {{proposal.quote_amount}} {{proposal.currency}}',
            'is_active' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('template.slug', 'crm-quotation')
            ->assertJsonPath('template.service', 'crm');

        $templateId = (int) $templateResponse->json('template.id');

        $generate1 = $this->postJson('/api/admin/proposals/generate', [
            'lead_id' => $lead->id,
            'proposal_template_id' => $templateId,
            'quote_amount' => 2500,
            'currency' => 'USD',
            'service' => 'crm',
            'title' => 'CRM Proposal',
        ])
            ->assertCreated()
            ->assertJsonPath('proposal.version_no', 1)
            ->assertJsonPath('proposal.status', 'draft')
            ->assertJsonPath('proposal.quote_amount', '2500.00');

        $proposalId = (int) $generate1->json('proposal.id');
        $shareToken = (string) $generate1->json('proposal.share_token');

        $this->assertDatabaseHas('attachments', [
            'tenant_id' => $tenant->id,
            'entity_type' => 'lead',
            'entity_id' => $lead->id,
            'kind' => 'proposal',
            'source' => 'proposal_generator',
        ]);

        $this->postJson('/api/admin/proposals/generate', [
            'lead_id' => $lead->id,
            'proposal_template_id' => $templateId,
            'quote_amount' => 3300,
            'currency' => 'USD',
            'service' => 'crm',
            'title' => 'CRM Proposal V2',
        ])
            ->assertCreated()
            ->assertJsonPath('proposal.version_no', 2)
            ->assertJsonPath('proposal.status', 'draft');

        $send = $this->postJson('/api/admin/proposals/'.$proposalId.'/send', [
            'channels' => ['email', 'whatsapp'],
        ])
            ->assertOk()
            ->assertJsonPath('proposal.status', 'sent');

        $this->assertCount(2, (array) $send->json('messages'));

        $this->assertDatabaseHas('messages', [
            'tenant_id' => $tenant->id,
            'lead_id' => $lead->id,
            'channel' => 'email',
            'direction' => 'outbound',
        ]);

        $this->assertDatabaseHas('messages', [
            'tenant_id' => $tenant->id,
            'lead_id' => $lead->id,
            'channel' => 'whatsapp',
            'direction' => 'outbound',
        ]);

        $this->get('/proposals/'.$shareToken)
            ->assertOk()
            ->assertSee('CRM Proposal');

        $this->assertDatabaseHas('proposals', [
            'id' => $proposalId,
            'status' => 'opened',
        ]);

        $this->post('/proposals/'.$shareToken.'/accept', [
            'accepted_by' => 'Nora Ali',
        ])->assertRedirect('/proposals/'.$shareToken);

        $this->assertDatabaseHas('proposals', [
            'id' => $proposalId,
            'status' => 'accepted',
            'accepted_by' => 'Nora Ali',
        ]);

        $this->assertDatabaseHas('activities', [
            'tenant_id' => $tenant->id,
            'subject_type' => 'App\\Models\\Proposal',
            'subject_id' => $proposalId,
            'type' => 'proposal.opened',
        ]);

        $this->assertDatabaseHas('activities', [
            'tenant_id' => $tenant->id,
            'subject_type' => 'App\\Models\\Proposal',
            'subject_id' => $proposalId,
            'type' => 'proposal.accepted',
        ]);

        $this->assertDatabaseHas('realtime_events', [
            'tenant_id' => $tenant->id,
            'subject_type' => 'App\\Models\\Proposal',
            'subject_id' => $proposalId,
            'event_name' => 'proposal.generated',
        ]);

        $this->assertDatabaseHas('realtime_events', [
            'tenant_id' => $tenant->id,
            'subject_type' => 'App\\Models\\Proposal',
            'subject_id' => $proposalId,
            'event_name' => 'proposal.sent',
        ]);

        $this->assertDatabaseHas('realtime_events', [
            'tenant_id' => $tenant->id,
            'subject_type' => 'App\\Models\\Proposal',
            'subject_id' => $proposalId,
            'event_name' => 'proposal.opened',
        ]);

        $this->assertDatabaseHas('realtime_events', [
            'tenant_id' => $tenant->id,
            'subject_type' => 'App\\Models\\Proposal',
            'subject_id' => $proposalId,
            'event_name' => 'proposal.accepted',
        ]);
    }
}
