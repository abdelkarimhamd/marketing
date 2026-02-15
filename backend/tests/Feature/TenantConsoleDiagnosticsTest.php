<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\IntegrationConnection;
use App\Models\IntegrationEventSubscription;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Models\WebhookInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantConsoleDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_tenant_console_with_diagnostics_and_fix_suggestions(): void
    {
        $tenantWithIssues = Tenant::factory()->create(['name' => 'Smart Cedra']);
        $healthyTenant = Tenant::factory()->create(['name' => 'Healthy Tenant']);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->seedIssueData($tenantWithIssues);

        Sanctum::actingAs($superAdmin);

        $payload = $this->getJson('/api/admin/tenant-console')
            ->assertOk()
            ->json();

        $this->assertNotNull(data_get($payload, 'generated_at'));
        $this->assertSame(2, (int) data_get($payload, 'summary.tenants_total'));
        $this->assertSame(1, (int) data_get($payload, 'summary.tenants_with_issues'));
        $this->assertSame(1, (int) data_get($payload, 'summary.tenants_with_critical'));
        $this->assertSame(1, (int) data_get($payload, 'summary.tenants_with_automation_errors'));
        $this->assertSame(1, (int) data_get($payload, 'summary.tenants_with_integration_issues'));
        $this->assertSame(1, (int) data_get($payload, 'summary.tenants_with_domain_issues'));

        $rows = collect($payload['tenants'] ?? []);
        $issueRow = $rows->firstWhere('id', $tenantWithIssues->id);
        $healthyRow = $rows->firstWhere('id', $healthyTenant->id);

        $this->assertIsArray($issueRow);
        $this->assertIsArray($healthyRow);
        $this->assertSame(1, (int) data_get($issueRow, 'diagnostics.automation.queued_stale_messages'));
        $this->assertSame(1, (int) data_get($issueRow, 'diagnostics.automation.webhooks_failed_last_24h'));
        $this->assertSame(1, (int) data_get($issueRow, 'diagnostics.integrations.failing_connections'));
        $this->assertSame(1, (int) data_get($issueRow, 'diagnostics.integrations.failing_subscriptions'));
        $this->assertSame(1, (int) data_get($issueRow, 'diagnostics.domains.verification_failed'));
        $this->assertSame(1, (int) data_get($issueRow, 'diagnostics.domains.ssl_failed'));

        $codes = collect(data_get($issueRow, 'fix_suggestions', []))
            ->pluck('code')
            ->all();

        $this->assertContains('dns_verification_failed', $codes);
        $this->assertContains('ssl_provision_failed', $codes);
        $this->assertContains('queue_backlog', $codes);
        $this->assertContains('webhook_failures', $codes);
        $this->assertContains('webhook_backlog', $codes);
        $this->assertContains('integration_errors', $codes);

        $this->assertSame(0, (int) data_get($healthyRow, 'alerts.total'));
    }

    public function test_super_admin_can_filter_tenant_console_to_one_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->seedIssueData($tenantA);
        $this->seedIssueData($tenantB);

        Sanctum::actingAs($superAdmin);

        $payload = $this->getJson('/api/admin/tenant-console?tenant_id='.$tenantA->id)
            ->assertOk()
            ->json();

        $this->assertSame($tenantA->id, (int) data_get($payload, 'tenant_id'));
        $this->assertSame(1, (int) data_get($payload, 'summary.tenants_total'));
        $this->assertCount(1, $payload['tenants']);
        $this->assertSame($tenantA->id, (int) data_get($payload, 'tenants.0.id'));
    }

    public function test_tenant_admin_cannot_access_tenant_console(): void
    {
        $tenant = Tenant::factory()->create();
        $tenantAdmin = User::factory()->tenantAdmin()->create([
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($tenantAdmin);

        $this->getJson('/api/admin/tenant-console?tenant_id='.$tenant->id)
            ->assertForbidden();
    }

    private function seedIssueData(Tenant $tenant): void
    {
        Message::query()
            ->withoutTenancy()
            ->create([
                'tenant_id' => $tenant->id,
                'direction' => 'outbound',
                'status' => 'failed',
                'channel' => 'email',
                'provider' => 'smtp',
                'provider_message_id' => 'failed-'.$tenant->id.'-1',
                'to' => 'lead@example.test',
                'from' => 'mailer@example.test',
                'subject' => 'Subject',
                'body' => 'Body',
            ]);

        $queuedMessage = Message::query()
            ->withoutTenancy()
            ->create([
                'tenant_id' => $tenant->id,
                'direction' => 'outbound',
                'status' => 'queued',
                'channel' => 'email',
                'provider' => 'smtp',
                'provider_message_id' => 'queued-'.$tenant->id.'-1',
                'to' => 'lead@example.test',
                'from' => 'mailer@example.test',
                'subject' => 'Queued',
                'body' => 'Queued body',
            ]);

        $queuedMessage->forceFill([
            'created_at' => now()->subMinutes(90),
            'updated_at' => now()->subMinutes(90),
        ])->saveQuietly();

        Activity::query()
            ->withoutTenancy()
            ->create([
                'tenant_id' => $tenant->id,
                'type' => 'campaign.message.failed',
                'subject_type' => Message::class,
                'subject_id' => $queuedMessage->id,
                'description' => 'Failed dispatch',
            ]);

        WebhookInbox::query()
            ->withoutTenancy()
            ->create([
                'tenant_id' => $tenant->id,
                'provider' => 'meta',
                'event' => 'message.failed',
                'external_id' => 'webhook-failed-'.$tenant->id,
                'payload' => '{}',
                'status' => 'failed',
                'received_at' => now()->subMinutes(20),
            ]);

        WebhookInbox::query()
            ->withoutTenancy()
            ->create([
                'tenant_id' => $tenant->id,
                'provider' => 'meta',
                'event' => 'message.pending',
                'external_id' => 'webhook-pending-'.$tenant->id,
                'payload' => '{}',
                'status' => 'pending',
                'received_at' => now()->subMinutes(40),
            ]);

        IntegrationConnection::query()
            ->withoutTenancy()
            ->create([
                'tenant_id' => $tenant->id,
                'provider' => 'meta',
                'name' => 'Meta Connection',
                'is_active' => true,
                'last_synced_at' => now()->subDays(2),
                'last_error' => 'Token expired',
            ]);

        IntegrationEventSubscription::query()
            ->withoutTenancy()
            ->create([
                'tenant_id' => $tenant->id,
                'name' => 'Lead Events',
                'endpoint_url' => 'https://hooks.example.test/lead',
                'events' => ['lead.created'],
                'is_active' => true,
                'last_delivered_at' => now()->subDays(10),
                'last_error' => '500 from downstream webhook',
            ]);

        TenantDomain::query()
            ->withoutTenancy()
            ->create([
                'tenant_id' => $tenant->id,
                'host' => 'broken-'.$tenant->id.'.example.test',
                'kind' => TenantDomain::KIND_LANDING,
                'is_primary' => true,
                'cname_target' => 'tenant.marketion.local',
                'verification_status' => TenantDomain::VERIFICATION_FAILED,
                'verification_error' => 'CNAME mismatch',
                'ssl_status' => TenantDomain::SSL_FAILED,
                'ssl_error' => 'SSL challenge failed',
            ]);
    }
}
