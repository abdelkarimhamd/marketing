<?php

namespace App\Services;

use App\Models\Experiment;
use App\Models\ExperimentAssignment;
use App\Models\ExperimentMetric;
use App\Models\ExperimentVariant;

class ExperimentService
{
    /**
     * Assign entity to variant (or holdout) deterministically.
     */
    public function assign(
        Experiment $experiment,
        string $assignmentKey,
        ?string $visitorId = null,
        ?int $leadId = null,
        array $meta = []
    ): ExperimentAssignment {
        $normalizedKey = trim($assignmentKey);

        if ($normalizedKey === '') {
            abort(422, 'assignment key is required for experiment assignment.');
        }

        $existing = ExperimentAssignment::query()
            ->withoutTenancy()
            ->where('tenant_id', (int) $experiment->tenant_id)
            ->where('experiment_id', (int) $experiment->id)
            ->where('assignment_key', $normalizedKey)
            ->first();

        if ($existing instanceof ExperimentAssignment) {
            return $existing;
        }

        $seed = sprintf('%d|%s', (int) $experiment->id, $normalizedKey);
        $bucket = hexdec(substr(hash('sha256', $seed), 0, 8)) % 10000;
        $holdoutThreshold = (int) round(max(0, min(100, (float) $experiment->holdout_pct)) * 100);
        $isHoldout = $bucket < $holdoutThreshold;

        $variant = null;
        $variantKey = null;

        if (! $isHoldout) {
            $variant = $this->pickVariant($experiment, $normalizedKey);
            $variantKey = $variant?->key;
        }

        return ExperimentAssignment::query()
            ->withoutTenancy()
            ->create([
                'tenant_id' => (int) $experiment->tenant_id,
                'experiment_id' => (int) $experiment->id,
                'experiment_variant_id' => $variant?->id,
                'lead_id' => $leadId,
                'visitor_id' => $visitorId,
                'assignment_key' => $normalizedKey,
                'variant_key' => $variantKey,
                'is_holdout' => $isHoldout,
                'assigned_at' => now(),
                'meta' => $meta,
            ]);
    }

    /**
     * Build high-level experiment results.
     *
     * @return array<string, mixed>
     */
    public function results(Experiment $experiment): array
    {
        $assignments = ExperimentAssignment::query()
            ->withoutTenancy()
            ->where('tenant_id', (int) $experiment->tenant_id)
            ->where('experiment_id', (int) $experiment->id)
            ->get();

        $metrics = ExperimentMetric::query()
            ->withoutTenancy()
            ->where('tenant_id', (int) $experiment->tenant_id)
            ->where('experiment_id', (int) $experiment->id)
            ->get();

        $variantBreakdown = $assignments
            ->groupBy(fn (ExperimentAssignment $assignment): string => $assignment->is_holdout
                ? '__holdout__'
                : ((string) ($assignment->variant_key ?? '__unknown__')))
            ->map(function ($rows, $key) use ($metrics): array {
                $count = $rows->count();

                $conversionMetric = $metrics
                    ->where('metric_key', 'conversion')
                    ->filter(function (ExperimentMetric $metric) use ($key): bool {
                        if ($key === '__holdout__') {
                            return $metric->experiment_variant_id === null;
                        }

                        return (string) ($metric->variant?->key ?? '') === (string) $key;
                    })
                    ->sum('metric_value');

                $conversionRate = $count > 0 ? ((float) $conversionMetric / $count) : 0.0;

                return [
                    'variant_key' => $key,
                    'assignments' => $count,
                    'conversions' => (float) $conversionMetric,
                    'conversion_rate' => $conversionRate,
                    'lift_vs_holdout' => null,
                ];
            })
            ->values();

        $holdoutRate = (float) ($variantBreakdown->firstWhere('variant_key', '__holdout__')['conversion_rate'] ?? 0.0);

        $variantBreakdown = $variantBreakdown
            ->map(static function (array $row) use ($holdoutRate): array {
                if ($row['variant_key'] === '__holdout__') {
                    $row['lift_vs_holdout'] = 0.0;
                } elseif ($holdoutRate > 0) {
                    $row['lift_vs_holdout'] = (($row['conversion_rate'] - $holdoutRate) / $holdoutRate) * 100;
                } else {
                    $row['lift_vs_holdout'] = null;
                }

                return $row;
            })
            ->values()
            ->all();

        return [
            'experiment_id' => (int) $experiment->id,
            'name' => $experiment->name,
            'scope' => $experiment->scope,
            'status' => $experiment->status,
            'holdout_pct' => (float) $experiment->holdout_pct,
            'summary' => [
                'assignments' => $assignments->count(),
                'metrics_count' => $metrics->count(),
            ],
            'variants' => $variantBreakdown,
        ];
    }

    private function pickVariant(Experiment $experiment, string $assignmentKey): ?ExperimentVariant
    {
        $variants = $experiment->variants()->orderByDesc('is_control')->orderBy('id')->get();

        if ($variants->isEmpty()) {
            return null;
        }

        $totalWeight = max(1, (int) $variants->sum(static fn (ExperimentVariant $variant): int => max(1, (int) $variant->weight)));
        $bucket = hexdec(substr(hash('sha256', sprintf('%d|%s|variant', (int) $experiment->id, $assignmentKey)), 0, 8)) % $totalWeight;

        $cursor = 0;

        foreach ($variants as $variant) {
            $cursor += max(1, (int) $variant->weight);

            if ($bucket < $cursor) {
                return $variant;
            }
        }

        return $variants->last();
    }
}
