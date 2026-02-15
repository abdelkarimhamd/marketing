<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\BillingUsageRecord;
use App\Models\IntegrationConnection;
use App\Models\IntegrationEventSubscription;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantHealthSnapshot;
use App\Models\TenantSubscription;
use App\Models\Unsubscribe;
use App\Models\WebhookInbox;
use Carbon\Carbon;

class HealthMetricsService
{
    /**
     * Compute tenant health and usage metrics.
     *
     * @return array<string, mixed>
     */
    public function tenantMetrics(Tenant $tenant): array
    {
        $tenantId = (int) $tenant->id;
        $messagesTotal = Message::query()->withoutTenancy()->where('tenant_id', $tenantId)->count();
        $delivered = Message::query()->withoutTenancy()->where('tenant_id', $tenantId)->whereIn('status', ['delivered', 'opened', 'clicked', 'read'])->count();
        $failed = Message::query()->withoutTenancy()->where('tenant_id', $tenantId)->where('status', 'failed')->count();
        $opened = Message::query()->withoutTenancy()->where('tenant_id', $tenantId)->whereNotNull('opened_at')->count();
        $replied = Message::query()->withoutTenancy()->where('tenant_id', $tenantId)->where('direction', 'inbound')->count();
        $optOuts = Unsubscribe::query()->withoutTenancy()->where('tenant_id', $tenantId)->count();
        $leadsCount = Lead::query()->withoutTenancy()->where('tenant_id', $tenantId)->count();

        $deliverabilityRate = $messagesTotal > 0 ? round(($delivered / $messagesTotal) * 100, 2) : 0.0;
        $bounceRate = $messagesTotal > 0 ? round(($failed / $messagesTotal) * 100, 2) : 0.0;
        $openRate = $messagesTotal > 0 ? round(($opened / $messagesTotal) * 100, 2) : 0.0;
        $replyRate = $messagesTotal > 0 ? round(($replied / $messagesTotal) * 100, 2) : 0.0;
        $optOutRate = $leadsCount > 0 ? round(($optOuts / $leadsCount) * 100, 2) : 0.0;

        $healthScore = $this->score(
            deliverabilityRate: $deliverabilityRate,
            bounceRate: $bounceRate,
            replyRate: $replyRate,
            optOutRate: $optOutRate,
        );

        $usage = $this->usageByChannel($tenantId);

        return [
            'tenant_id' => $tenantId,
            'health_score' => $healthScore,
            'deliverability_rate' => $deliverabilityRate,
            'bounce_rate' => $bounceRate,
            'open_rate' => $openRate,
            'reply_rate' => $replyRate,
            'opt_out_rate' => $optOutRate,
            'messages_total' => $messagesTotal,
            'usage' => $usage,
            'warnings' => $this->usageWarnings($tenantId, $usage),
        ];
    }

    /**
     * Return one tenant-focused support console payload.
     *
     * @return array<string, mixed>
     */
    public function tenantConsoleMetrics(Tenant $tenant): array
    {
        $base = $this->tenantMetrics($tenant);
        $tenantId = (int) $tenant->id;

        $diagnostics = [
            'deliverability' => [
                'health_score' => (float) ($base['health_score'] ?? 0),
                'messages_total' => (int) ($base['messages_total'] ?? 0),
                'deliverability_rate' => (float) ($base['deliverability_rate'] ?? 0),
                'bounce_rate' => (float) ($base['bounce_rate'] ?? 0),
                'open_rate' => (float) ($base['open_rate'] ?? 0),
                'reply_rate' => (float) ($base['reply_rate'] ?? 0),
                'opt_out_rate' => (float) ($base['opt_out_rate'] ?? 0),
            ],
            'automation' => $this->automationDiagnostics($tenantId),
            'integrations' => $this->integrationDiagnostics($tenantId),
            'domains' => $this->domainDiagnostics($tenantId),
        ];

        $suggestions = $this->fixSuggestions($tenant, $base, $diagnostics);
        $alerts = $this->alertCounters($suggestions);

        return array_merge($base, [
            'tenant' => [
                'id' => (int) $tenant->id,
                'name' => (string) $tenant->name,
                'slug' => (string) $tenant->slug,
                'domain' => $tenant->domain,
                'is_active' => (bool) $tenant->is_active,
                'timezone' => $tenant->timezone,
            ],
            'diagnostics' => $diagnostics,
            'fix_suggestions' => $suggestions,
            'alerts' => $alerts,
        ]);
    }

    /**
     * Persist daily snapshot for historical tracking.
     */
    public function storeDailySnapshot(Tenant $tenant): TenantHealthSnapshot
    {
        $metrics = $this->tenantMetrics($tenant);

        return TenantHealthSnapshot::query()
            ->withoutTenancy()
            ->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'snapshot_date' => now()->toDateString(),
                ],
                [
                    'health_score' => $metrics['health_score'],
                    'metrics' => $metrics,
                ]
            );
    }

    /**
     * @return array<string, array{messages: int, cost_total: float}>
     */
    private function usageByChannel(int $tenantId): array
    {
        $rows = BillingUsageRecord::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('period_date', '>=', now()->startOfMonth()->toDateString())
            ->selectRaw('channel, SUM(messages_count) as messages, SUM(cost_total) as cost_total')
            ->groupBy('channel')
            ->get();

        $usage = [];

        foreach ($rows as $row) {
            $usage[$row->channel] = [
                'messages' => (int) $row->messages,
                'cost_total' => (float) $row->cost_total,
            ];
        }

        return $usage;
    }

    /**
     * @param array<string, array{messages: int, cost_total: float}> $usage
     * @return list<string>
     */
    private function usageWarnings(int $tenantId, array $usage): array
    {
        $subscription = TenantSubscription::query()
            ->withoutTenancy()
            ->with('plan')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['active', 'trialing'])
            ->latest('id')
            ->first();

        if ($subscription === null) {
            return [];
        }

        $bundle = (int) ($subscription->message_bundle_override ?? $subscription->plan?->message_bundle ?? 0);

        if ($bundle <= 0) {
            return [];
        }

        $used = array_sum(array_map(static fn (array $row): int => $row['messages'], $usage));
        $ratio = $used / max(1, $bundle);
        $warnings = [];

        if ($ratio >= 1) {
            $warnings[] = 'Message bundle limit exceeded. Overage billing may apply.';
        } elseif ($ratio >= 0.85) {
            $warnings[] = 'Message usage is above 85% of plan limit.';
        }

        return $warnings;
    }

    /**
     * @return array{
     *   failed_messages_last_24h: int,
     *   campaign_failures_last_24h: int,
     *   queued_stale_messages: int,
     *   webhooks_failed_last_24h: int,
     *   webhooks_pending_stale: int
     * }
     */
    private function automationDiagnostics(int $tenantId): array
    {
        $dayAgo = now()->subDay();
        $staleQueueThreshold = now()->subMinutes(30);
        $staleWebhookThreshold = now()->subMinutes(15);

        return [
            'failed_messages_last_24h' => Message::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->where('status', 'failed')
                ->where('created_at', '>=', $dayAgo)
                ->count(),
            'campaign_failures_last_24h' => Activity::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->where('type', 'campaign.message.failed')
                ->where('created_at', '>=', $dayAgo)
                ->count(),
            'queued_stale_messages' => Message::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->where('status', 'queued')
                ->where('created_at', '<=', $staleQueueThreshold)
                ->count(),
            'webhooks_failed_last_24h' => WebhookInbox::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->where('status', 'failed')
                ->where('created_at', '>=', $dayAgo)
                ->count(),
            'webhooks_pending_stale' => WebhookInbox::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->where('status', 'pending')
                ->where('received_at', '<=', $staleWebhookThreshold)
                ->count(),
        ];
    }

    /**
     * @return array{
     *   connections_total: int,
     *   active_connections: int,
     *   failing_connections: int,
     *   stale_connections: int,
     *   subscriptions_total: int,
     *   active_subscriptions: int,
     *   failing_subscriptions: int,
     *   stale_subscriptions: int
     * }
     */
    private function integrationDiagnostics(int $tenantId): array
    {
        $staleSyncThreshold = now()->subDay();
        $staleDeliveryThreshold = now()->subDays(7);

        return [
            'connections_total' => IntegrationConnection::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->count(),
            'active_connections' => IntegrationConnection::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->count(),
            'failing_connections' => IntegrationConnection::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->whereNotNull('last_error')
                ->count(),
            'stale_connections' => IntegrationConnection::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->where(function ($query) use ($staleSyncThreshold): void {
                    $query
                        ->whereNull('last_synced_at')
                        ->orWhere('last_synced_at', '<=', $staleSyncThreshold);
                })
                ->count(),
            'subscriptions_total' => IntegrationEventSubscription::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->count(),
            'active_subscriptions' => IntegrationEventSubscription::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->count(),
            'failing_subscriptions' => IntegrationEventSubscription::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->whereNotNull('last_error')
                ->count(),
            'stale_subscriptions' => IntegrationEventSubscription::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->where(function ($query) use ($staleDeliveryThreshold): void {
                    $query
                        ->whereNull('last_delivered_at')
                        ->orWhere('last_delivered_at', '<=', $staleDeliveryThreshold);
                })
                ->count(),
        ];
    }

    /**
     * @return array{
     *   domains_total: int,
     *   verification_pending: int,
     *   verification_failed: int,
     *   ssl_pending: int,
     *   ssl_failed: int
     * }
     */
    private function domainDiagnostics(int $tenantId): array
    {
        return [
            'domains_total' => TenantDomain::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->count(),
            'verification_pending' => TenantDomain::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->where('verification_status', TenantDomain::VERIFICATION_PENDING)
                ->count(),
            'verification_failed' => TenantDomain::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->where('verification_status', TenantDomain::VERIFICATION_FAILED)
                ->count(),
            'ssl_pending' => TenantDomain::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->whereIn('ssl_status', [TenantDomain::SSL_PENDING, TenantDomain::SSL_PROVISIONING])
                ->count(),
            'ssl_failed' => TenantDomain::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->where('ssl_status', TenantDomain::SSL_FAILED)
                ->count(),
        ];
    }

    /**
     * Build human-readable fix suggestions for customer success team.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $diagnostics
     * @return list<array<string, string>>
     */
    private function fixSuggestions(Tenant $tenant, array $base, array $diagnostics): array
    {
        $suggestions = [];
        $deliverability = is_array($diagnostics['deliverability'] ?? null) ? $diagnostics['deliverability'] : [];
        $automation = is_array($diagnostics['automation'] ?? null) ? $diagnostics['automation'] : [];
        $integrations = is_array($diagnostics['integrations'] ?? null) ? $diagnostics['integrations'] : [];
        $domains = is_array($diagnostics['domains'] ?? null) ? $diagnostics['domains'] : [];
        $messagesTotal = (int) ($deliverability['messages_total'] ?? 0);

        if ((int) ($domains['verification_failed'] ?? 0) > 0) {
            $suggestions[] = [
                'code' => 'dns_verification_failed',
                'severity' => 'critical',
                'title' => 'Domain verification failed',
                'description' => 'One or more domains failed CNAME verification for this tenant.',
                'action' => 'Open Settings > Domains, fix CNAME target, then run Verify again.',
            ];
        } elseif ((int) ($domains['verification_pending'] ?? 0) > 0) {
            $suggestions[] = [
                'code' => 'dns_verification_pending',
                'severity' => 'warning',
                'title' => 'Domain verification still pending',
                'description' => 'Some domains were added but not yet verified. DNS might not be propagated.',
                'action' => 'Check CNAME host/target and wait for propagation before retrying verification.',
            ];
        }

        if ((int) ($domains['ssl_failed'] ?? 0) > 0) {
            $suggestions[] = [
                'code' => 'ssl_provision_failed',
                'severity' => 'critical',
                'title' => 'SSL provisioning failed',
                'description' => 'At least one tenant domain has failed SSL status.',
                'action' => 'Verify domain ownership and DNS, then trigger SSL provisioning again.',
            ];
        }

        if ((int) ($automation['queued_stale_messages'] ?? 0) > 0) {
            $suggestions[] = [
                'code' => 'queue_backlog',
                'severity' => 'critical',
                'title' => 'Queued messages are stale',
                'description' => 'Outbound messages are stuck in queue longer than expected.',
                'action' => 'Check queue worker process and retry failed jobs for tenant '.$tenant->id.'.',
            ];
        }

        if ((int) ($automation['webhooks_failed_last_24h'] ?? 0) > 0) {
            $suggestions[] = [
                'code' => 'webhook_failures',
                'severity' => 'warning',
                'title' => 'Webhook processing failures detected',
                'description' => 'Inbound webhook events failed in the last 24 hours.',
                'action' => 'Inspect Webhooks Inbox errors and provider signature/configuration.',
            ];
        }

        if ((int) ($automation['webhooks_pending_stale'] ?? 0) > 0) {
            $suggestions[] = [
                'code' => 'webhook_backlog',
                'severity' => 'warning',
                'title' => 'Webhook backlog detected',
                'description' => 'Webhook events are pending and not processed in time.',
                'action' => 'Review webhook parser exceptions and verify application workers are healthy.',
            ];
        }

        if ((int) ($integrations['failing_connections'] ?? 0) > 0 || (int) ($integrations['failing_subscriptions'] ?? 0) > 0) {
            $suggestions[] = [
                'code' => 'integration_errors',
                'severity' => 'warning',
                'title' => 'Integration errors detected',
                'description' => 'Some active integrations or event subscriptions report last_error.',
                'action' => 'Open Integrations module and re-authenticate or fix endpoint credentials.',
            ];
        }

        if ((float) ($deliverability['bounce_rate'] ?? 0) >= 8.0 && $messagesTotal >= 20) {
            $suggestions[] = [
                'code' => 'high_bounce_rate',
                'severity' => 'critical',
                'title' => 'High bounce rate',
                'description' => 'Bounce rate is above safe threshold and may hurt sender reputation.',
                'action' => 'Clean invalid emails, verify sender domain (SPF/DKIM/DMARC), and pause risky lists.',
            ];
        } elseif ((float) ($deliverability['deliverability_rate'] ?? 0) < 85.0 && $messagesTotal >= 20) {
            $suggestions[] = [
                'code' => 'low_deliverability',
                'severity' => 'warning',
                'title' => 'Low deliverability',
                'description' => 'Delivery success is below target for recent sends.',
                'action' => 'Check provider response errors and domain/auth settings before next launch.',
            ];
        }

        if ((float) ($deliverability['open_rate'] ?? 0) < 8.0 && $messagesTotal >= 50) {
            $suggestions[] = [
                'code' => 'low_open_rate',
                'severity' => 'info',
                'title' => 'Low open rate',
                'description' => 'Recipients are receiving but not opening messages.',
                'action' => 'Run subject-line/content A/B tests and tighten segment targeting.',
            ];
        }

        if ($suggestions === [] && is_array($base['warnings'] ?? null) && $base['warnings'] !== []) {
            $suggestions[] = [
                'code' => 'plan_usage_warning',
                'severity' => 'info',
                'title' => 'Usage warning',
                'description' => (string) $base['warnings'][0],
                'action' => 'Review Billing usage and increase bundle if needed.',
            ];
        }

        return $suggestions;
    }

    /**
     * @param list<array<string, string>> $suggestions
     * @return array{critical: int, warning: int, info: int, total: int}
     */
    private function alertCounters(array $suggestions): array
    {
        $critical = 0;
        $warning = 0;
        $info = 0;

        foreach ($suggestions as $suggestion) {
            $severity = mb_strtolower((string) ($suggestion['severity'] ?? 'info'));

            if ($severity === 'critical') {
                $critical++;
            } elseif ($severity === 'warning') {
                $warning++;
            } else {
                $info++;
            }
        }

        return [
            'critical' => $critical,
            'warning' => $warning,
            'info' => $info,
            'total' => $critical + $warning + $info,
        ];
    }

    private function score(float $deliverabilityRate, float $bounceRate, float $replyRate, float $optOutRate): float
    {
        $score = 50.0;
        $score += min(30.0, $deliverabilityRate * 0.3);
        $score += min(15.0, $replyRate * 0.4);
        $score -= min(20.0, $bounceRate * 0.5);
        $score -= min(20.0, $optOutRate * 1.0);

        return round(max(0, min(100, $score)), 2);
    }
}
