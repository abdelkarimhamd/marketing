<?php

namespace Tests\Feature;

use App\Models\BillingUsageRecord;
use App\Models\Campaign;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingProfitabilityReportingTest extends TestCase
{
    use RefreshDatabase;

    private int $providerMessageSequence = 1;

    public function test_track_dispatched_message_records_costs_revenue_profit_and_usage_totals(): void
    {
        $tenant = Tenant::factory()->create([
            'currency' => 'sar',
            'settings' => [
                'cost_engine' => [
                    'provider_costs' => [
                        'email' => [
                            'smtp' => 0.0110,
                        ],
                    ],
                    'overhead_per_message' => [
                        'email' => 0.0040,
                    ],
                    'revenue_per_message' => [
                        'email' => 0.0300,
                    ],
                ],
            ],
        ]);

        $message = Message::query()
            ->withoutTenancy()
            ->create([
                'tenant_id' => $tenant->id,
                'direction' => 'outbound',
                'status' => 'sent',
                'channel' => 'email',
                'to' => 'lead@example.test',
                'from' => 'sales@example.test',
                'subject' => 'Welcome',
                'body' => 'Hello from Smart Cedra',
                'provider' => 'smtp',
                'provider_message_id' => $this->nextProviderMessageId(),
                'sent_at' => now(),
                'meta' => [
                    'billing' => [
                        'overage' => true,
                        'overage_amount' => 0.0050,
                    ],
                ],
            ]);

        app(BillingService::class)->trackDispatchedMessage($message);

        $message->refresh();

        $this->assertNotNull($message->cost_tracked_at);
        $this->assertSame('SAR', $message->cost_currency);
        $this->assertEqualsWithDelta(0.0110, (float) $message->provider_cost, 0.0001);
        $this->assertEqualsWithDelta(0.0040, (float) $message->overhead_cost, 0.0001);
        $this->assertEqualsWithDelta(0.0150, (float) $message->cost_estimate, 0.0001);
        $this->assertEqualsWithDelta(0.0350, (float) $message->revenue_amount, 0.0001);
        $this->assertEqualsWithDelta(0.0200, (float) $message->profit_amount, 0.0001);
        $this->assertEqualsWithDelta(57.1429, (float) $message->margin_percent, 0.0001);
        $this->assertSame('tenant_settings', data_get($message->meta, 'cost_engine.reason.provider_cost_source'));
        $this->assertSame('tenant_settings', data_get($message->meta, 'cost_engine.reason.overhead_cost_source'));
        $this->assertSame('tenant_settings', data_get($message->meta, 'cost_engine.reason.revenue_source'));

        $usage = BillingUsageRecord::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->where('channel', 'email')
            ->first();

        $this->assertNotNull($usage);
        $this->assertSame(1, (int) $usage->messages_count);
        $this->assertEqualsWithDelta(0.0150, (float) $usage->cost_total, 0.0001);
        $this->assertEqualsWithDelta(0.0110, (float) $usage->provider_cost_total, 0.0001);
        $this->assertEqualsWithDelta(0.0040, (float) $usage->overhead_cost_total, 0.0001);
        $this->assertEqualsWithDelta(0.0350, (float) $usage->revenue_total, 0.0001);
        $this->assertEqualsWithDelta(0.0200, (float) $usage->profit_total, 0.0001);
    }

    public function test_profitability_endpoint_returns_expected_summary_and_groupings(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);

        $campaignA = Campaign::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Retention',
            'slug' => 'retention',
            'channel' => 'email',
            'campaign_type' => Campaign::TYPE_BROADCAST,
            'status' => Campaign::STATUS_RUNNING,
            'settings' => [],
            'metrics' => [],
        ]);

        $campaignB = Campaign::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Promo SMS',
            'slug' => 'promo-sms',
            'channel' => 'sms',
            'campaign_type' => Campaign::TYPE_BROADCAST,
            'status' => Campaign::STATUS_RUNNING,
            'settings' => [],
            'metrics' => [],
        ]);

        $this->createTrackedMessage(
            tenantId: $tenant->id,
            campaignId: $campaignA->id,
            channel: 'email',
            provider: 'smtp',
            providerCost: 0.0070,
            overheadCost: 0.0030,
            totalCost: 0.0100,
            revenue: 0.0200,
            profit: 0.0100
        );

        $this->createTrackedMessage(
            tenantId: $tenant->id,
            campaignId: $campaignA->id,
            channel: 'email',
            provider: 'smtp',
            providerCost: 0.0080,
            overheadCost: 0.0040,
            totalCost: 0.0120,
            revenue: 0.0180,
            profit: 0.0060
        );

        $this->createTrackedMessage(
            tenantId: $tenant->id,
            campaignId: $campaignB->id,
            channel: 'sms',
            provider: 'twilio',
            providerCost: 0.0250,
            overheadCost: 0.0050,
            totalCost: 0.0300,
            revenue: 0.0500,
            profit: 0.0200
        );

        Sanctum::actingAs($admin);

        $payload = $this->getJson('/api/admin/billing/profitability?tenant_id='.$tenant->id)
            ->assertOk()
            ->json();

        $summary = $payload['summary'];
        $this->assertSame(3, (int) $summary['messages_count']);
        $this->assertEqualsWithDelta(0.0520, (float) $summary['total_cost'], 0.0001);
        $this->assertEqualsWithDelta(0.0880, (float) $summary['revenue_total'], 0.0001);
        $this->assertEqualsWithDelta(0.0360, (float) $summary['profit_total'], 0.0001);
        $this->assertEqualsWithDelta(40.9091, (float) $summary['margin_percent'], 0.0001);

        $byChannel = collect($payload['by_channel'])->keyBy('channel');
        $this->assertSame(2, (int) data_get($byChannel, 'email.messages_count'));
        $this->assertSame(1, (int) data_get($byChannel, 'sms.messages_count'));
        $this->assertEqualsWithDelta(0.0220, (float) data_get($byChannel, 'email.total_cost'), 0.0001);
        $this->assertEqualsWithDelta(0.0300, (float) data_get($byChannel, 'sms.total_cost'), 0.0001);

        $byCampaign = collect($payload['by_campaign'])->keyBy(
            static fn (array $row): string => (string) ($row['campaign_id'] ?? 'null')
        );

        $this->assertSame(2, (int) data_get($byCampaign, (string) $campaignA->id.'.messages_count'));
        $this->assertSame(1, (int) data_get($byCampaign, (string) $campaignB->id.'.messages_count'));
        $this->assertEqualsWithDelta(0.0160, (float) data_get($byCampaign, (string) $campaignA->id.'.profit_total'), 0.0001);
        $this->assertEqualsWithDelta(0.0200, (float) data_get($byCampaign, (string) $campaignB->id.'.profit_total'), 0.0001);
    }

    public function test_margin_alerts_endpoint_uses_tenant_threshold_and_min_message_count(): void
    {
        $tenant = Tenant::factory()->create([
            'settings' => [
                'cost_engine' => [
                    'margin_alert_threshold_percent' => 30,
                    'margin_alert_min_messages' => 2,
                ],
            ],
        ]);
        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);

        $campaign = Campaign::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Low Margin Email',
            'slug' => 'low-margin-email',
            'channel' => 'email',
            'campaign_type' => Campaign::TYPE_BROADCAST,
            'status' => Campaign::STATUS_RUNNING,
            'settings' => [],
            'metrics' => [],
        ]);

        $this->createTrackedMessage(
            tenantId: $tenant->id,
            campaignId: $campaign->id,
            channel: 'email',
            provider: 'smtp',
            providerCost: 0.0160,
            overheadCost: 0.0020,
            totalCost: 0.0180,
            revenue: 0.0200,
            profit: 0.0020
        );

        $this->createTrackedMessage(
            tenantId: $tenant->id,
            campaignId: $campaign->id,
            channel: 'email',
            provider: 'smtp',
            providerCost: 0.0170,
            overheadCost: 0.0020,
            totalCost: 0.0190,
            revenue: 0.0200,
            profit: 0.0010
        );

        $this->createTrackedMessage(
            tenantId: $tenant->id,
            campaignId: $campaign->id,
            channel: 'sms',
            provider: 'twilio',
            providerCost: 0.0100,
            overheadCost: 0.0010,
            totalCost: 0.0110,
            revenue: 0.0500,
            profit: 0.0390
        );

        Sanctum::actingAs($admin);

        $payload = $this->getJson('/api/admin/billing/margin-alerts?tenant_id='.$tenant->id)
            ->assertOk()
            ->json();

        $this->assertSame(1, (int) $payload['count']);
        $this->assertCount(1, $payload['alerts']);
        $this->assertSame('email', $payload['alerts'][0]['channel']);
        $this->assertSame(2, (int) $payload['alerts'][0]['messages_count']);
        $this->assertEqualsWithDelta(30.0, (float) $payload['alerts'][0]['threshold_percent'], 0.0001);
        $this->assertSame('warning', $payload['alerts'][0]['severity']);
        $this->assertNotEmpty($payload['alerts'][0]['reasons']);

        $this->getJson('/api/admin/billing/margin-alerts?tenant_id='.$tenant->id.'&threshold_percent=5')
            ->assertOk()
            ->assertJsonPath('count', 0);
    }

    private function createTrackedMessage(
        int $tenantId,
        ?int $campaignId,
        string $channel,
        string $provider,
        float $providerCost,
        float $overheadCost,
        float $totalCost,
        float $revenue,
        float $profit
    ): Message {
        $margin = $revenue > 0
            ? round(($profit / $revenue) * 100, 4)
            : ($totalCost > 0 ? -100.0 : 0.0);

        return Message::query()
            ->withoutTenancy()
            ->create([
                'tenant_id' => $tenantId,
                'campaign_id' => $campaignId,
                'direction' => 'outbound',
                'status' => 'sent',
                'channel' => $channel,
                'to' => $channel === 'email' ? 'lead@example.test' : '+15555550100',
                'from' => $channel === 'email' ? 'sender@example.test' : '+15555550200',
                'provider' => $provider,
                'provider_message_id' => $this->nextProviderMessageId(),
                'cost_estimate' => round($totalCost, 4),
                'provider_cost' => round($providerCost, 4),
                'overhead_cost' => round($overheadCost, 4),
                'revenue_amount' => round($revenue, 4),
                'profit_amount' => round($profit, 4),
                'margin_percent' => $margin,
                'cost_currency' => 'USD',
                'cost_tracked_at' => now(),
                'sent_at' => now(),
            ]);
    }

    private function nextProviderMessageId(): string
    {
        $id = $this->providerMessageSequence;
        $this->providerMessageSequence++;

        return 'provider-msg-'.$id;
    }
}
