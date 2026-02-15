<?php

namespace App\Services;

use App\Jobs\RunDataQualityJob;
use App\Models\DataQualityRun;
use App\Models\Lead;
use App\Models\MergeSuggestion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DataQualityService
{
    /**
     * Queue a data-quality run.
     */
    public function queueRun(int $tenantId, ?int $requestedBy, string $runType = 'full'): DataQualityRun
    {
        $run = DataQualityRun::query()
            ->withoutTenancy()
            ->create([
                'tenant_id' => $tenantId,
                'run_type' => $runType,
                'status' => 'queued',
                'requested_by' => $requestedBy,
            ]);

        RunDataQualityJob::dispatch((int) $run->id);

        return $run;
    }

    /**
     * Execute one queued run synchronously.
     */
    public function executeRun(DataQualityRun $run): DataQualityRun
    {
        $run->forceFill([
            'status' => 'running',
            'started_at' => now(),
            'error_message' => null,
        ])->save();

        $stats = [
            'leads_processed' => 0,
            'leads_normalized' => 0,
            'merge_suggestions_created' => 0,
        ];

        try {
            $enrichment = app(LeadEnrichmentService::class);

            Lead::query()
                ->withoutTenancy()
                ->where('tenant_id', (int) $run->tenant_id)
                ->orderBy('id')
                ->chunkById(200, function ($leads) use (&$stats, $enrichment): void {
                    foreach ($leads as $lead) {
                        $stats['leads_processed']++;

                        $payload = [
                            'first_name' => $lead->first_name,
                            'last_name' => $lead->last_name,
                            'email' => $lead->email,
                            'phone' => $lead->phone,
                            'company' => $lead->company,
                            'city' => $lead->city,
                            'country_code' => $lead->country_code,
                            'score' => $lead->score,
                            'meta' => is_array($lead->meta) ? $lead->meta : [],
                        ];

                        try {
                            $normalized = $enrichment->enrich($payload);
                        } catch (\Throwable) {
                            continue;
                        }

                        $updates = [];

                        foreach (['email', 'phone', 'company', 'city', 'country_code', 'score', 'meta'] as $field) {
                            if (array_key_exists($field, $normalized) && $lead->{$field} !== $normalized[$field]) {
                                $updates[$field] = $normalized[$field];
                            }
                        }

                        if ($updates !== []) {
                            $lead->forceFill($updates)->save();
                            $stats['leads_normalized']++;
                        }
                    }
                });

            $stats['merge_suggestions_created'] = $this->buildMergeSuggestions((int) $run->tenant_id);

            $run->forceFill([
                'status' => 'completed',
                'finished_at' => now(),
                'stats_json' => $stats,
            ])->save();
        } catch (\Throwable $exception) {
            $run->forceFill([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),
                'stats_json' => $stats,
            ])->save();
        }

        return $run->refresh();
    }

    /**
     * Build duplicate suggestions by exact email/phone match.
     */
    public function buildMergeSuggestions(int $tenantId): int
    {
        $created = 0;

        $created += $this->buildSuggestionsByField(
            tenantId: $tenantId,
            field: 'email',
            reason: 'exact_email',
            confidence: 0.98,
        );

        $created += $this->buildSuggestionsByField(
            tenantId: $tenantId,
            field: 'phone',
            reason: 'exact_phone',
            confidence: 0.95,
        );

        return $created;
    }

    /**
     * Review suggestion status.
     */
    public function reviewSuggestion(MergeSuggestion $suggestion, string $status, ?int $reviewedBy): MergeSuggestion
    {
        $allowed = ['pending', 'approved', 'rejected', 'merged', 'skipped'];

        if (! in_array($status, $allowed, true)) {
            abort(422, 'Invalid merge suggestion status.');
        }

        $suggestion->forceFill([
            'status' => $status,
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => now(),
        ])->save();

        if ($status === 'merged') {
            $this->mergeLeads((int) $suggestion->tenant_id, (int) $suggestion->candidate_a_id, (int) $suggestion->candidate_b_id);
        }

        return $suggestion->refresh();
    }

    private function buildSuggestionsByField(int $tenantId, string $field, string $reason, float $confidence): int
    {
        $created = 0;

        /** @var Collection<int, object> $groups */
        $groups = Lead::query()
            ->withoutTenancy()
            ->select($field)
            ->selectRaw('GROUP_CONCAT(id) as lead_ids')
            ->where('tenant_id', $tenantId)
            ->whereNotNull($field)
            ->where($field, '!=', '')
            ->groupBy($field)
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $ids = collect(explode(',', (string) $group->lead_ids))
                ->map(static fn (string $id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->values();

            for ($i = 0; $i < $ids->count(); $i++) {
                for ($j = $i + 1; $j < $ids->count(); $j++) {
                    $a = min($ids[$i], $ids[$j]);
                    $b = max($ids[$i], $ids[$j]);

                    $existing = MergeSuggestion::query()
                        ->withoutTenancy()
                        ->where('tenant_id', $tenantId)
                        ->where('candidate_a_id', $a)
                        ->where('candidate_b_id', $b)
                        ->where('reason', $reason)
                        ->exists();

                    if ($existing) {
                        continue;
                    }

                    MergeSuggestion::query()
                        ->withoutTenancy()
                        ->create([
                            'tenant_id' => $tenantId,
                            'candidate_a_id' => $a,
                            'candidate_b_id' => $b,
                            'reason' => $reason,
                            'confidence' => $confidence,
                            'status' => 'pending',
                            'meta' => ['field' => $field],
                        ]);

                    $created++;
                }
            }
        }

        return $created;
    }

    private function mergeLeads(int $tenantId, int $candidateAId, int $candidateBId): void
    {
        $a = Lead::query()->withoutTenancy()->where('tenant_id', $tenantId)->whereKey($candidateAId)->first();
        $b = Lead::query()->withoutTenancy()->where('tenant_id', $tenantId)->whereKey($candidateBId)->first();

        if (! $a instanceof Lead || ! $b instanceof Lead) {
            return;
        }

        DB::transaction(function () use ($a, $b): void {
            $primary = $a->id <= $b->id ? $a : $b;
            $secondary = $a->id <= $b->id ? $b : $a;

            $primary->forceFill([
                'first_name' => $primary->first_name ?: $secondary->first_name,
                'last_name' => $primary->last_name ?: $secondary->last_name,
                'email' => $primary->email ?: $secondary->email,
                'phone' => $primary->phone ?: $secondary->phone,
                'company' => $primary->company ?: $secondary->company,
                'city' => $primary->city ?: $secondary->city,
                'country_code' => $primary->country_code ?: $secondary->country_code,
                'score' => max((int) $primary->score, (int) $secondary->score),
            ])->save();

            $secondary->messages()->update(['lead_id' => $primary->id]);
            $secondary->callLogs()->update(['lead_id' => $primary->id]);
            $secondary->appointments()->update(['lead_id' => $primary->id]);
            $secondary->proposals()->update(['lead_id' => $primary->id]);
            $secondary->trackingEvents()->update(['lead_id' => $primary->id]);
            $secondary->trackingVisitors()->update(['lead_id' => $primary->id]);

            $primaryTagIds = $primary->tags()->pluck('tags.id')->all();
            $secondaryTags = $secondary->tags()->pluck('tags.id')->all();

            if ($secondaryTags !== []) {
                $sync = collect(array_unique(array_merge($primaryTagIds, $secondaryTags)))
                    ->mapWithKeys(static fn (int $tagId): array => [$tagId => ['tenant_id' => (int) $primary->tenant_id]])
                    ->all();

                $primary->tags()->sync($sync);
            }

            $secondary->delete();
        });
    }
}
