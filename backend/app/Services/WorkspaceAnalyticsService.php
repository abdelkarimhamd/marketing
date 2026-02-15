<?php

namespace App\Services;

use App\Models\Message;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class WorkspaceAnalyticsService
{
    /**
     * Build cross-tenant workspace analytics payload.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function report(array $filters = []): array
    {
        [$dateFrom, $dateTo, $previousFrom, $previousTo] = $this->resolvePeriods($filters);
        $channel = $this->resolveChannel($filters);
        $tenantIds = $this->resolveTenantIds($filters);

        $currentSummaryRow = $this->aggregateSummary($dateFrom, $dateTo, $channel, $tenantIds);
        $previousSummaryRow = $this->aggregateSummary($previousFrom, $previousTo, $channel, $tenantIds);
        $summary = $this->summaryWithGrowth($currentSummaryRow, $previousSummaryRow);

        $byTenant = $this->aggregateByTenant(
            $dateFrom,
            $dateTo,
            $previousFrom,
            $previousTo,
            $channel,
            $tenantIds,
            (float) ($summary['revenue_total'] ?? 0)
        );

        $byChannel = $this->aggregateByChannel(
            $dateFrom,
            $dateTo,
            $previousFrom,
            $previousTo,
            $channel,
            $tenantIds,
            (float) ($summary['revenue_total'] ?? 0)
        );

        return [
            'period' => [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'previous_date_from' => $previousFrom->toDateString(),
                'previous_date_to' => $previousTo->toDateString(),
                'days' => $dateFrom->diffInDays($dateTo) + 1,
            ],
            'filters' => [
                'channel' => $channel,
                'tenant_ids' => $tenantIds,
            ],
            'summary' => $summary,
            'by_tenant' => $byTenant,
            'by_channel' => $byChannel,
            'trend' => $this->aggregateTrend($dateFrom, $dateTo, $channel, $tenantIds),
        ];
    }

    /**
     * Build tenant/channel comparison rows for CSV/BI export.
     *
     * @param array<string, mixed> $filters
     * @return array{filename: string, csv: string}
     */
    public function exportToCsv(array $filters = []): array
    {
        $report = $this->report($filters);
        $period = is_array($report['period'] ?? null) ? $report['period'] : [];
        $byTenant = is_array($report['by_tenant'] ?? null) ? $report['by_tenant'] : [];
        $byChannel = is_array($report['by_channel'] ?? null) ? $report['by_channel'] : [];

        $headers = [
            'row_type',
            'entity_id',
            'entity_name',
            'channel',
            'date_from',
            'date_to',
            'previous_date_from',
            'previous_date_to',
            'messages_count',
            'messages_growth_percent',
            'total_cost',
            'revenue_total',
            'revenue_growth_percent',
            'profit_total',
            'profit_growth_percent',
            'margin_percent',
            'roi_percent',
            'revenue_share_percent',
        ];

        $rows = [];

        foreach ($byTenant as $tenantRow) {
            if (! is_array($tenantRow)) {
                continue;
            }

            $rows[] = [
                'row_type' => 'tenant',
                'entity_id' => $tenantRow['tenant_id'] ?? null,
                'entity_name' => $tenantRow['tenant_name'] ?? null,
                'channel' => null,
                'date_from' => $period['date_from'] ?? null,
                'date_to' => $period['date_to'] ?? null,
                'previous_date_from' => $period['previous_date_from'] ?? null,
                'previous_date_to' => $period['previous_date_to'] ?? null,
                'messages_count' => $tenantRow['messages_count'] ?? 0,
                'messages_growth_percent' => $tenantRow['messages_growth_percent'] ?? 0,
                'total_cost' => $tenantRow['total_cost'] ?? 0,
                'revenue_total' => $tenantRow['revenue_total'] ?? 0,
                'revenue_growth_percent' => $tenantRow['revenue_growth_percent'] ?? 0,
                'profit_total' => $tenantRow['profit_total'] ?? 0,
                'profit_growth_percent' => $tenantRow['profit_growth_percent'] ?? 0,
                'margin_percent' => $tenantRow['margin_percent'] ?? 0,
                'roi_percent' => $tenantRow['roi_percent'] ?? 0,
                'revenue_share_percent' => $tenantRow['revenue_share_percent'] ?? 0,
            ];
        }

        foreach ($byChannel as $channelRow) {
            if (! is_array($channelRow)) {
                continue;
            }

            $rows[] = [
                'row_type' => 'channel',
                'entity_id' => $channelRow['channel'] ?? null,
                'entity_name' => $channelRow['channel'] ?? null,
                'channel' => $channelRow['channel'] ?? null,
                'date_from' => $period['date_from'] ?? null,
                'date_to' => $period['date_to'] ?? null,
                'previous_date_from' => $period['previous_date_from'] ?? null,
                'previous_date_to' => $period['previous_date_to'] ?? null,
                'messages_count' => $channelRow['messages_count'] ?? 0,
                'messages_growth_percent' => $channelRow['messages_growth_percent'] ?? 0,
                'total_cost' => $channelRow['total_cost'] ?? 0,
                'revenue_total' => $channelRow['revenue_total'] ?? 0,
                'revenue_growth_percent' => $channelRow['revenue_growth_percent'] ?? 0,
                'profit_total' => $channelRow['profit_total'] ?? 0,
                'profit_growth_percent' => $channelRow['profit_growth_percent'] ?? 0,
                'margin_percent' => $channelRow['margin_percent'] ?? 0,
                'roi_percent' => $channelRow['roi_percent'] ?? 0,
                'revenue_share_percent' => $channelRow['revenue_share_percent'] ?? 0,
            ];
        }

        $csv = $this->toCsv($headers, $rows);
        $filename = sprintf(
            'workspace-analytics-%s-%s.csv',
            str_replace('-', '', (string) ($period['date_from'] ?? now()->toDateString())),
            str_replace('-', '', (string) ($period['date_to'] ?? now()->toDateString()))
        );

        return [
            'filename' => $filename,
            'csv' => $csv,
        ];
    }

    /**
     * @return array{Carbon, Carbon, Carbon, Carbon}
     */
    private function resolvePeriods(array $filters): array
    {
        $dateFrom = $this->toCarbon($filters['date_from'] ?? null)?->startOfDay()
            ?? now()->subDays(29)->startOfDay();
        $dateTo = $this->toCarbon($filters['date_to'] ?? null)?->endOfDay()
            ?? now()->endOfDay();

        if ($dateTo->lt($dateFrom)) {
            $dateTo = $dateFrom->copy()->endOfDay();
        }

        $days = max(
            1,
            $dateFrom->copy()->startOfDay()->diffInDays($dateTo->copy()->startOfDay()) + 1
        );
        $previousTo = $dateFrom->copy()->subSecond();
        $previousFrom = $previousTo->copy()->subDays($days - 1)->startOfDay();

        return [$dateFrom, $dateTo, $previousFrom, $previousTo];
    }

    private function resolveChannel(array $filters): ?string
    {
        $channel = $filters['channel'] ?? null;

        if (! is_string($channel)) {
            return null;
        }

        $normalized = mb_strtolower(trim($channel));

        return in_array($normalized, ['email', 'sms', 'whatsapp'], true) ? $normalized : null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<int>
     */
    private function resolveTenantIds(array $filters): array
    {
        $raw = $filters['tenant_ids'] ?? [];

        if (is_string($raw) && trim($raw) !== '') {
            $raw = explode(',', $raw);
        }

        if (! is_array($raw)) {
            return [];
        }

        $ids = [];

        foreach ($raw as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                $ids[] = (int) $candidate;
            }
        }

        if ($ids === []) {
            return [];
        }

        return Tenant::query()
            ->whereIn('id', array_values(array_unique($ids)))
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param list<int> $tenantIds
     */
    private function aggregateSummary(Carbon $from, Carbon $to, ?string $channel, array $tenantIds): object
    {
        return $this->baseMessageQuery($from, $to, $channel, $tenantIds)
            ->selectRaw('
                COUNT(*) as messages_count,
                COUNT(DISTINCT messages.tenant_id) as active_tenants,
                COALESCE(SUM(messages.cost_estimate), 0) as total_cost,
                COALESCE(SUM(messages.revenue_amount), 0) as revenue_total,
                COALESCE(SUM(messages.profit_amount), 0) as profit_total
            ')
            ->first()
            ?? (object) [
                'messages_count' => 0,
                'active_tenants' => 0,
                'total_cost' => 0,
                'revenue_total' => 0,
                'profit_total' => 0,
            ];
    }

    /**
     * @param list<int> $tenantIds
     * @return list<array<string, mixed>>
     */
    private function aggregateByTenant(
        Carbon $from,
        Carbon $to,
        Carbon $previousFrom,
        Carbon $previousTo,
        ?string $channel,
        array $tenantIds,
        float $totalRevenue
    ): array {
        $currentRows = $this->baseMessageQuery($from, $to, $channel, $tenantIds)
            ->join('tenants', 'tenants.id', '=', 'messages.tenant_id')
            ->selectRaw('
                messages.tenant_id,
                tenants.name as tenant_name,
                COUNT(*) as messages_count,
                COALESCE(SUM(messages.cost_estimate), 0) as total_cost,
                COALESCE(SUM(messages.revenue_amount), 0) as revenue_total,
                COALESCE(SUM(messages.profit_amount), 0) as profit_total
            ')
            ->groupBy('messages.tenant_id', 'tenants.name')
            ->orderByDesc(DB::raw('COALESCE(SUM(messages.profit_amount), 0)'))
            ->get();

        $previousRows = $this->baseMessageQuery($previousFrom, $previousTo, $channel, $tenantIds)
            ->selectRaw('
                messages.tenant_id,
                COUNT(*) as messages_count,
                COALESCE(SUM(messages.cost_estimate), 0) as total_cost,
                COALESCE(SUM(messages.revenue_amount), 0) as revenue_total,
                COALESCE(SUM(messages.profit_amount), 0) as profit_total
            ')
            ->groupBy('messages.tenant_id')
            ->get()
            ->keyBy(static fn (object $row): int => (int) $row->tenant_id);

        $rank = 1;

        return $currentRows->map(function (object $row) use ($previousRows, $totalRevenue, &$rank): array {
            $metrics = $this->metricsFromRow($row);
            $previousMetrics = $this->metricsFromRow($previousRows->get((int) $row->tenant_id));

            $mapped = [
                'rank' => $rank,
                'tenant_id' => (int) $row->tenant_id,
                'tenant_name' => (string) $row->tenant_name,
                'messages_count' => $metrics['messages_count'],
                'total_cost' => $metrics['total_cost'],
                'revenue_total' => $metrics['revenue_total'],
                'profit_total' => $metrics['profit_total'],
                'margin_percent' => $metrics['margin_percent'],
                'roi_percent' => $metrics['roi_percent'],
                'messages_growth_percent' => $this->growthPercent($metrics['messages_count'], $previousMetrics['messages_count']),
                'revenue_growth_percent' => $this->growthPercent($metrics['revenue_total'], $previousMetrics['revenue_total']),
                'profit_growth_percent' => $this->growthPercent($metrics['profit_total'], $previousMetrics['profit_total']),
                'revenue_share_percent' => $totalRevenue > 0
                    ? round(($metrics['revenue_total'] / $totalRevenue) * 100, 2)
                    : 0.0,
            ];

            $rank++;

            return $mapped;
        })->all();
    }

    /**
     * @param list<int> $tenantIds
     * @return list<array<string, mixed>>
     */
    private function aggregateByChannel(
        Carbon $from,
        Carbon $to,
        Carbon $previousFrom,
        Carbon $previousTo,
        ?string $channel,
        array $tenantIds,
        float $totalRevenue
    ): array {
        $currentRows = $this->baseMessageQuery($from, $to, $channel, $tenantIds)
            ->selectRaw('
                messages.channel,
                COUNT(*) as messages_count,
                COALESCE(SUM(messages.cost_estimate), 0) as total_cost,
                COALESCE(SUM(messages.revenue_amount), 0) as revenue_total,
                COALESCE(SUM(messages.profit_amount), 0) as profit_total
            ')
            ->groupBy('messages.channel')
            ->orderByDesc(DB::raw('COALESCE(SUM(messages.profit_amount), 0)'))
            ->get();

        $previousRows = $this->baseMessageQuery($previousFrom, $previousTo, $channel, $tenantIds)
            ->selectRaw('
                messages.channel,
                COUNT(*) as messages_count,
                COALESCE(SUM(messages.cost_estimate), 0) as total_cost,
                COALESCE(SUM(messages.revenue_amount), 0) as revenue_total,
                COALESCE(SUM(messages.profit_amount), 0) as profit_total
            ')
            ->groupBy('messages.channel')
            ->get()
            ->keyBy(static fn (object $row): string => (string) ($row->channel ?? 'unknown'));

        return $currentRows->map(function (object $row) use ($previousRows, $totalRevenue): array {
            $channelKey = (string) ($row->channel ?? 'unknown');
            $metrics = $this->metricsFromRow($row);
            $previousMetrics = $this->metricsFromRow($previousRows->get($channelKey));

            return [
                'channel' => $channelKey,
                'messages_count' => $metrics['messages_count'],
                'total_cost' => $metrics['total_cost'],
                'revenue_total' => $metrics['revenue_total'],
                'profit_total' => $metrics['profit_total'],
                'margin_percent' => $metrics['margin_percent'],
                'roi_percent' => $metrics['roi_percent'],
                'messages_growth_percent' => $this->growthPercent($metrics['messages_count'], $previousMetrics['messages_count']),
                'revenue_growth_percent' => $this->growthPercent($metrics['revenue_total'], $previousMetrics['revenue_total']),
                'profit_growth_percent' => $this->growthPercent($metrics['profit_total'], $previousMetrics['profit_total']),
                'revenue_share_percent' => $totalRevenue > 0
                    ? round(($metrics['revenue_total'] / $totalRevenue) * 100, 2)
                    : 0.0,
            ];
        })->all();
    }

    /**
     * @param list<int> $tenantIds
     * @return list<array<string, mixed>>
     */
    private function aggregateTrend(Carbon $from, Carbon $to, ?string $channel, array $tenantIds): array
    {
        $rows = $this->baseMessageQuery($from, $to, $channel, $tenantIds)
            ->selectRaw('
                DATE(messages.cost_tracked_at) as metric_date,
                COUNT(*) as messages_count,
                COALESCE(SUM(messages.cost_estimate), 0) as total_cost,
                COALESCE(SUM(messages.revenue_amount), 0) as revenue_total,
                COALESCE(SUM(messages.profit_amount), 0) as profit_total
            ')
            ->groupBy(DB::raw('DATE(messages.cost_tracked_at)'))
            ->orderBy(DB::raw('DATE(messages.cost_tracked_at)'))
            ->get()
            ->mapWithKeys(static fn (object $row): array => [
                (string) $row->metric_date => [
                    'messages_count' => (int) ($row->messages_count ?? 0),
                    'total_cost' => round((float) ($row->total_cost ?? 0), 4),
                    'revenue_total' => round((float) ($row->revenue_total ?? 0), 4),
                    'profit_total' => round((float) ($row->profit_total ?? 0), 4),
                ],
            ]);

        $trend = [];
        $cursor = $from->copy()->startOfDay();
        $lastDay = $to->copy()->startOfDay();

        while ($cursor->lte($lastDay)) {
            $key = $cursor->toDateString();
            $bucket = $rows->get($key, [
                'messages_count' => 0,
                'total_cost' => 0.0,
                'revenue_total' => 0.0,
                'profit_total' => 0.0,
            ]);

            $trend[] = [
                'date' => $key,
                'messages_count' => (int) ($bucket['messages_count'] ?? 0),
                'total_cost' => round((float) ($bucket['total_cost'] ?? 0), 4),
                'revenue_total' => round((float) ($bucket['revenue_total'] ?? 0), 4),
                'profit_total' => round((float) ($bucket['profit_total'] ?? 0), 4),
            ];

            $cursor->addDay();
        }

        return $trend;
    }

    /**
     * @param list<int> $tenantIds
     */
    private function baseMessageQuery(Carbon $from, Carbon $to, ?string $channel, array $tenantIds): Builder
    {
        $query = Message::query()
            ->withoutTenancy()
            ->where('messages.direction', 'outbound')
            ->whereNotNull('messages.cost_tracked_at')
            ->whereBetween('messages.cost_tracked_at', [$from, $to]);

        if ($channel !== null) {
            $query->where('messages.channel', $channel);
        }

        if ($tenantIds !== []) {
            $query->whereIn('messages.tenant_id', $tenantIds);
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryWithGrowth(object $currentRow, object $previousRow): array
    {
        $current = $this->metricsFromRow($currentRow);
        $previous = $this->metricsFromRow($previousRow);
        $currentActiveTenants = (int) ($currentRow->active_tenants ?? 0);
        $previousActiveTenants = (int) ($previousRow->active_tenants ?? 0);

        return [
            'active_tenants' => $currentActiveTenants,
            'active_tenants_growth_percent' => $this->growthPercent($currentActiveTenants, $previousActiveTenants),
            'messages_count' => $current['messages_count'],
            'messages_growth_percent' => $this->growthPercent($current['messages_count'], $previous['messages_count']),
            'total_cost' => $current['total_cost'],
            'revenue_total' => $current['revenue_total'],
            'revenue_growth_percent' => $this->growthPercent($current['revenue_total'], $previous['revenue_total']),
            'profit_total' => $current['profit_total'],
            'profit_growth_percent' => $this->growthPercent($current['profit_total'], $previous['profit_total']),
            'margin_percent' => $current['margin_percent'],
            'roi_percent' => $current['roi_percent'],
            'roi_growth_percent' => $this->growthPercent($current['roi_percent'], $previous['roi_percent']),
            'previous_messages_count' => $previous['messages_count'],
            'previous_total_cost' => $previous['total_cost'],
            'previous_revenue_total' => $previous['revenue_total'],
            'previous_profit_total' => $previous['profit_total'],
            'previous_margin_percent' => $previous['margin_percent'],
            'previous_roi_percent' => $previous['roi_percent'],
        ];
    }

    /**
     * @param object|null $row
     * @return array{
     *   messages_count: int,
     *   total_cost: float,
     *   revenue_total: float,
     *   profit_total: float,
     *   margin_percent: float,
     *   roi_percent: float
     * }
     */
    private function metricsFromRow(?object $row): array
    {
        $messagesCount = (int) ($row->messages_count ?? 0);
        $totalCost = round((float) ($row->total_cost ?? 0), 4);
        $revenueTotal = round((float) ($row->revenue_total ?? 0), 4);
        $profitTotal = round((float) ($row->profit_total ?? 0), 4);
        $marginPercent = $revenueTotal > 0
            ? round(($profitTotal / $revenueTotal) * 100, 4)
            : ($totalCost > 0 ? -100.0 : 0.0);
        $roiPercent = $totalCost > 0
            ? round(($profitTotal / $totalCost) * 100, 4)
            : ($profitTotal > 0 ? 100.0 : 0.0);

        return [
            'messages_count' => $messagesCount,
            'total_cost' => $totalCost,
            'revenue_total' => $revenueTotal,
            'profit_total' => $profitTotal,
            'margin_percent' => $marginPercent,
            'roi_percent' => $roiPercent,
        ];
    }

    private function growthPercent(float|int $currentValue, float|int $previousValue): float
    {
        $current = (float) $currentValue;
        $previous = (float) $previousValue;

        if (abs($previous) < 0.000001) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / abs($previous)) * 100, 2);
    }

    /**
     * @param list<string> $headers
     * @param list<array<string, mixed>> $rows
     */
    private function toCsv(array $headers, array $rows): string
    {
        $stream = fopen('php://temp', 'w+');

        if ($stream === false) {
            return '';
        }

        fputcsv($stream, $headers);

        foreach ($rows as $row) {
            $line = [];

            foreach ($headers as $header) {
                $line[] = $row[$header] ?? null;
            }

            fputcsv($stream, $line);
        }

        rewind($stream);
        $csv = stream_get_contents($stream) ?: '';
        fclose($stream);

        return $csv;
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
}
