<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkspaceAnalyticsReportingTest extends TestCase
{
    use RefreshDatabase;

    private int $providerSequence = 1;

    public function test_super_admin_can_view_cross_tenant_workspace_analytics(): void
    {
        $tenantA = Tenant::factory()->create(['name' => 'Alpha LLC']);
        $tenantB = Tenant::factory()->create(['name' => 'Bravo Group']);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->createTrackedMessage($tenantA, 'email', '2026-02-02 10:00:00', 50.0, 100.0, 50.0);
        $this->createTrackedMessage($tenantA, 'email', '2026-02-03 11:00:00', 30.0, 60.0, 30.0);
        $this->createTrackedMessage($tenantB, 'sms', '2026-02-04 12:00:00', 60.0, 120.0, 60.0);

        $this->createTrackedMessage($tenantA, 'email', '2026-01-26 09:00:00', 20.0, 40.0, 20.0);
        $this->createTrackedMessage($tenantB, 'sms', '2026-01-27 09:30:00', 20.0, 40.0, 20.0);

        Sanctum::actingAs($superAdmin);

        $payload = $this->getJson('/api/admin/billing/workspace-analytics?date_from=2026-02-01&date_to=2026-02-07')
            ->assertOk()
            ->json();

        $this->assertSame('2026-02-01', data_get($payload, 'period.date_from'));
        $this->assertSame('2026-02-07', data_get($payload, 'period.date_to'));
        $this->assertSame('2026-01-25', data_get($payload, 'period.previous_date_from'));
        $this->assertSame('2026-01-31', data_get($payload, 'period.previous_date_to'));

        $this->assertSame(2, (int) data_get($payload, 'summary.active_tenants'));
        $this->assertSame(3, (int) data_get($payload, 'summary.messages_count'));
        $this->assertEqualsWithDelta(280.0, (float) data_get($payload, 'summary.revenue_total'), 0.0001);
        $this->assertEqualsWithDelta(140.0, (float) data_get($payload, 'summary.total_cost'), 0.0001);
        $this->assertEqualsWithDelta(140.0, (float) data_get($payload, 'summary.profit_total'), 0.0001);
        $this->assertEqualsWithDelta(50.0, (float) data_get($payload, 'summary.margin_percent'), 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) data_get($payload, 'summary.roi_percent'), 0.0001);

        $tenantRows = collect($payload['by_tenant'] ?? []);
        $channelRows = collect($payload['by_channel'] ?? []);

        $this->assertCount(2, $tenantRows);
        $this->assertCount(2, $channelRows);
        $this->assertTrue($tenantRows->contains(fn (array $row): bool => ($row['tenant_name'] ?? null) === 'Alpha LLC'));
        $this->assertTrue($tenantRows->contains(fn (array $row): bool => ($row['tenant_name'] ?? null) === 'Bravo Group'));
        $this->assertTrue($channelRows->contains(fn (array $row): bool => ($row['channel'] ?? null) === 'email'));
        $this->assertTrue($channelRows->contains(fn (array $row): bool => ($row['channel'] ?? null) === 'sms'));

        $alphaRow = $tenantRows->firstWhere('tenant_name', 'Alpha LLC');
        $this->assertIsArray($alphaRow);
        $this->assertEqualsWithDelta(160.0, (float) data_get($alphaRow, 'revenue_total'), 0.0001);
        $this->assertGreaterThan(0, (float) data_get($alphaRow, 'revenue_growth_percent'));
    }

    public function test_super_admin_can_export_workspace_analytics_csv(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Export Tenant']);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->createTrackedMessage($tenant, 'email', '2026-02-05 08:00:00', 10.0, 30.0, 20.0);

        Sanctum::actingAs($superAdmin);

        $response = $this->get('/api/admin/billing/workspace-analytics/export?date_from=2026-02-01&date_to=2026-02-07');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertSee('row_type,entity_id,entity_name', false);
        $response->assertSee('tenant', false);
        $response->assertSee('Export Tenant', false);
    }

    public function test_tenant_admin_cannot_access_workspace_analytics(): void
    {
        $tenant = Tenant::factory()->create();
        $tenantAdmin = User::factory()->tenantAdmin()->create([
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($tenantAdmin);

        $this->getJson('/api/admin/billing/workspace-analytics?date_from=2026-02-01&date_to=2026-02-07')
            ->assertForbidden();

        $this->get('/api/admin/billing/workspace-analytics/export?date_from=2026-02-01&date_to=2026-02-07')
            ->assertForbidden();
    }

    private function createTrackedMessage(
        Tenant $tenant,
        string $channel,
        string $trackedAt,
        float $cost,
        float $revenue,
        float $profit
    ): void {
        $provider = $channel === 'sms' ? 'twilio' : 'smtp';

        Message::query()
            ->withoutTenancy()
            ->create([
                'tenant_id' => $tenant->id,
                'direction' => 'outbound',
                'status' => 'sent',
                'channel' => $channel,
                'to' => $channel === 'email' ? 'lead@example.test' : '+15550001111',
                'from' => $channel === 'email' ? 'marketing@example.test' : '+15550002222',
                'provider' => $provider,
                'provider_message_id' => 'workspace-'.$this->providerSequence++,
                'cost_estimate' => round($cost, 4),
                'revenue_amount' => round($revenue, 4),
                'profit_amount' => round($profit, 4),
                'cost_tracked_at' => $trackedAt,
                'sent_at' => $trackedAt,
            ]);
    }
}
