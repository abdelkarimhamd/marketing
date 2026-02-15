<?php

namespace App\Services;

use App\Models\Message;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProfitabilityReportingService
{
    public function __construct(
        private readonly ChannelCostEngineService $costEngineService
    ) {
    }

    /**
     * Build profitability report for tenant/campaign/channel dimensions.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function report(?int $tenantId, array $filters = []): array
    {
        $base = $this->baseQuery($tenantId, $filters);
        $summary = $this->aggregateSummary($base);

        $byChannel = $this->aggregateByChannel($base);
        $byCampaign = $this->aggregateByCampaign($base);
        $byTenant = $tenantId === null
            ? $this->aggregateByTenant($base)
            : [];

        return [
            'summary' => $summary,
            'by_channel' => $byChannel,
            'by_campaign' => $byCampaign,
            'by_tenant' => $byTenant,
            'filters' => [
                'tenant_id' => $tenantId,
                'channel' => isset($filters['channel']) ? (string) $filters['channel'] : null,
                'campaign_id' => isset($filters['campaign_id']) ? (int) $filters['campaign_id'] : null,
                'date_from' => $this->toDateString($filters['date_from'] ?? null),
                'date_to' => $this->toDateString($filters['date_to'] ?? null),
            ],
        ];
    }

    /**
     * Build margin alerts for low-profit segments.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function marginAlerts(?int $tenantId, array $filters = []): array
    {
        $base = $this->baseQuery($tenantId, $filters);
        $rows = $this->aggregateForAlerts($base);
        $thresholdOverride = $this->floatValue($filters['threshold_percent'] ?? null);

        $alerts = [];

        foreach ($rows as $row) {
            $rowTenantId = (int) $row->tenant_id;
            $threshold = $thresholdOverride ?? $this->costEngineService->marginAlertThresholdPercent($rowTenantId);
            $minMessages = $this->costEngineService->marginAlertMinMessages($rowTenantId);
            $metrics = $this->rowMetrics($row);

            if ($metrics['messages_count'] < $minMessages) {
                continue;
            }

            if ($metrics['margin_percent'] >= $threshold) {
                continue;
            }

            $alerts[] = [
                'tenant_id' => $rowTenantId,
                'tenant_name' => (string) ($row->tenant_name ?? ''),
                'campaign_id' => $row->campaign_id !== null ? (int) $row->campaign_id : null,
                'campaign_name' => is_string($row->campaign_name ?? null) ? $row->campaign_name : null,
                'channel' => (string) $row->channel,
                'threshold_percent' => round($threshold, 2),
                ...$metrics,
                'severity' => $metrics['margin_percent'] < 0 ? 'critical' : 'warning',
                'reasons' => $this->alertReasons($metrics),
            ];
        }

        usort(
            $alerts,
            static fn (array $a, array $b): int => $a['margin_percent'] <=> $b['margin_percent']
        );

        return [
            'alerts' => $alerts,
            'count' => count($alerts),
            'filters' => [
                'tenant_id' => $tenantId,
                'channel' => isset($filters['channel']) ? (string) $filters['channel'] : null,
                'campaign_id' => isset($filters['campaign_id']) ? (int) $filters['campaign_id'] : null,
                'date_from' => $this->toDateString($filters['date_from'] ?? null),
                'date_to' => $this->toDateString($filters['date_to'] ?? null),
                'threshold_percent' => $thresholdOverride,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function baseQuery(?int $tenantId, array $filters): Builder
    {
        $query = Message::query()
            ->withoutTenancy()
            ->where('messages.direction', 'outbound')
            ->whereNotNull('messages.cost_tracked_at');

        if ($tenantId !== null) {
            $query->where('messages.tenant_id', $tenantId);
        }

        if (isset($filters['channel']) && is_string($filters['channel']) && trim($filters['channel']) !== '') {
            $query->where('messages.channel', mb_strtolower(trim($filters['channel'])));
        }

        if (isset($filters['campaign_id']) && is_numeric($filters['campaign_id']) && (int) $filters['campaign_id'] > 0) {
            $query->where('messages.campaign_id', (int) $filters['campaign_id']);
        }

        if (isset($filters['date_from'])) {
            $from = $this->toCarbon($filters['date_from']);

            if ($from instanceof Carbon) {
                $query->whereDate('messages.cost_tracked_at', '>=', $from->toDateString());
            }
        }

        if (isset($filters['date_to'])) {
            $to = $this->toCarbon($filters['date_to']);

            if ($to instanceof Carbon) {
                $query->whereDate('messages.cost_tracked_at', '<=', $to->toDateString());
            }
        }

        return $query;
    }

    private function aggregateSummary(Builder $base): array
    {
        $row = (clone $base)
            ->selectRaw($this->aggregateSelectRaw())
            ->first();

        if ($row === null) {
            return $this->emptySummary();
        }

        return $this->rowMetrics($row);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function aggregateByChannel(Builder $base): array
    {
        $rows = (clone $base)
            ->selectRaw('channel, '.$this->aggregateSelectRaw())
            ->groupBy('channel')
            ->orderByDesc(DB::raw('SUM(revenue_amount) - SUM(cost_estimate)'))
            ->get();

        return $rows->map(function ($row): array {
            return [
                'channel' => (string) $row->channel,
                ...$this->rowMetrics($row),
            ];
        })->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function aggregateByCampaign(Builder $base): array
    {
        $rows = (clone $base)
            ->leftJoin('campaigns', 'campaigns.id', '=', 'messages.campaign_id')
            ->selectRaw('messages.campaign_id, campaigns.name as campaign_name, '.$this->aggregateSelectRaw())
            ->groupBy('messages.campaign_id', 'campaigns.name')
            ->orderByDesc(DB::raw('SUM(revenue_amount) - SUM(cost_estimate)'))
            ->get();

        return $rows->map(function ($row): array {
            return [
                'campaign_id' => $row->campaign_id !== null ? (int) $row->campaign_id : null,
                'campaign_name' => is_string($row->campaign_name ?? null) ? $row->campaign_name : null,
                ...$this->rowMetrics($row),
            ];
        })->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function aggregateByTenant(Builder $base): array
    {
        $rows = (clone $base)
            ->join('tenants', 'tenants.id', '=', 'messages.tenant_id')
            ->selectRaw('messages.tenant_id, tenants.name as tenant_name, '.$this->aggregateSelectRaw())
            ->groupBy('messages.tenant_id', 'tenants.name')
            ->orderByDesc(DB::raw('SUM(revenue_amount) - SUM(cost_estimate)'))
            ->get();

        return $rows->map(function ($row): array {
            return [
                'tenant_id' => (int) $row->tenant_id,
                'tenant_name' => (string) ($row->tenant_name ?? ''),
                ...$this->rowMetrics($row),
            ];
        })->all();
    }

    /**
     * @return Collection<int, object>
     */
    private function aggregateForAlerts(Builder $base): Collection
    {
        return (clone $base)
            ->join('tenants', 'tenants.id', '=', 'messages.tenant_id')
            ->leftJoin('campaigns', 'campaigns.id', '=', 'messages.campaign_id')
            ->selectRaw('
                messages.tenant_id,
                tenants.name as tenant_name,
                messages.campaign_id,
                campaigns.name as campaign_name,
                messages.channel,
                '.$this->aggregateSelectRaw().'
            ')
            ->groupBy(
                'messages.tenant_id',
                'tenants.name',
                'messages.campaign_id',
                'campaigns.name',
                'messages.channel'
            )
            ->orderBy('messages.tenant_id')
            ->orderBy('messages.channel')
            ->get();
    }

    private function aggregateSelectRaw(): string
    {
        return '
            COUNT(*) as messages_count,
            COALESCE(SUM(cost_estimate), 0) as total_cost,
            COALESCE(SUM(provider_cost), 0) as provider_cost_total,
            COALESCE(SUM(overhead_cost), 0) as overhead_cost_total,
            COALESCE(SUM(revenue_amount), 0) as revenue_total,
            COALESCE(SUM(profit_amount), 0) as profit_total
        ';
    }

    /**
     * @param object $row
     * @return array<string, mixed>
     */
    private function rowMetrics(object $row): array
    {
        $messagesCount = (int) ($row->messages_count ?? 0);
        $totalCost = round((float) ($row->total_cost ?? 0), 4);
        $providerCost = round((float) ($row->provider_cost_total ?? 0), 4);
        $overheadCost = round((float) ($row->overhead_cost_total ?? 0), 4);
        $revenueTotal = round((float) ($row->revenue_total ?? 0), 4);
        $profitTotal = round((float) ($row->profit_total ?? 0), 4);

        $marginPercent = $revenueTotal > 0
            ? round(($profitTotal / $revenueTotal) * 100, 4)
            : ($totalCost > 0 ? -100.0 : 0.0);

        $providerShare = $totalCost > 0
            ? round(($providerCost / $totalCost) * 100, 2)
            : 0.0;

        $overheadShare = $totalCost > 0
            ? round(($overheadCost / $totalCost) * 100, 2)
            : 0.0;

        return [
            'messages_count' => $messagesCount,
            'provider_cost_total' => $providerCost,
            'overhead_cost_total' => $overheadCost,
            'total_cost' => $totalCost,
            'revenue_total' => $revenueTotal,
            'profit_total' => $profitTotal,
            'margin_percent' => $marginPercent,
            'avg_cost_per_message' => $messagesCount > 0 ? round($totalCost / $messagesCount, 4) : 0.0,
            'avg_revenue_per_message' => $messagesCount > 0 ? round($revenueTotal / $messagesCount, 4) : 0.0,
            'avg_profit_per_message' => $messagesCount > 0 ? round($profitTotal / $messagesCount, 4) : 0.0,
            'provider_cost_share_percent' => $providerShare,
            'overhead_cost_share_percent' => $overheadShare,
        ];
    }

    /**
     * @param array<string, mixed> $metrics
     * @return list<string>
     */
    private function alertReasons(array $metrics): array
    {
        $reasons = [];

        if ((float) ($metrics['revenue_total'] ?? 0) <= 0) {
            $reasons[] = 'No revenue mapping found. Configure per-channel revenue rates.';
        }

        if ((float) ($metrics['provider_cost_share_percent'] ?? 0) >= 70) {
            $reasons[] = 'Provider delivery cost dominates total message cost.';
        }

        if ((float) ($metrics['overhead_cost_share_percent'] ?? 0) >= 35) {
            $reasons[] = 'Operational overhead is a large portion of message cost.';
        }

        if ($reasons === []) {
            $reasons[] = 'Margin is below the configured threshold.';
        }

        return $reasons;
    }

    private function emptySummary(): array
    {
        return [
            'messages_count' => 0,
            'provider_cost_total' => 0.0,
            'overhead_cost_total' => 0.0,
            'total_cost' => 0.0,
            'revenue_total' => 0.0,
            'profit_total' => 0.0,
            'margin_percent' => 0.0,
            'avg_cost_per_message' => 0.0,
            'avg_revenue_per_message' => 0.0,
            'avg_profit_per_message' => 0.0,
            'provider_cost_share_percent' => 0.0,
            'overhead_cost_share_percent' => 0.0,
        ];
    }

    private function toDateString(mixed $value): ?string
    {
        $carbon = $this->toCarbon($value);

        return $carbon?->toDateString();
    }

    private function toCarbon(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function floatValue(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
