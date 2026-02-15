<?php

namespace App\Services;

use App\Jobs\GenerateLeadCopilotJob;
use App\Models\AiRecommendation;
use App\Models\AiSummary;
use App\Models\Lead;
use App\Models\Message;
use App\Services\Ai\LlmManager;
use Illuminate\Support\Facades\Cache;

class CopilotService
{
    public function __construct(private readonly LlmManager $llmManager)
    {
    }

    /**
     * Dispatch async copilot generation.
     */
    public function dispatchGenerate(int $tenantId, int $leadId, ?int $requestedBy = null): void
    {
        GenerateLeadCopilotJob::dispatch($tenantId, $leadId, $requestedBy);
    }

    /**
     * Generate summary and recommendations now.
     *
     * @return array{summary:AiSummary,recommendations:list<AiRecommendation>}
     */
    public function generateNow(Lead $lead, ?int $requestedBy = null): array
    {
        $tenantId = (int) $lead->tenant_id;

        $this->enforceRateLimit($tenantId);

        $context = $this->buildContext($lead);
        $provider = $this->llmManager->provider();

        $summaryText = $provider->summarize($context);
        $recommendations = $provider->recommend($context);

        $summary = AiSummary::query()
            ->withoutTenancy()
            ->create([
                'tenant_id' => $tenantId,
                'lead_id' => (int) $lead->id,
                'summary' => $summaryText,
                'model' => $provider->key(),
                'generated_at' => now(),
                'meta' => [
                    'requested_by' => $requestedBy,
                ],
            ]);

        AiRecommendation::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('lead_id', (int) $lead->id)
            ->delete();

        $created = [];

        foreach ($recommendations as $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = trim((string) ($item['type'] ?? ''));

            if ($type === '') {
                continue;
            }

            $created[] = AiRecommendation::query()
                ->withoutTenancy()
                ->create([
                    'tenant_id' => $tenantId,
                    'lead_id' => (int) $lead->id,
                    'type' => $type,
                    'payload_json' => is_array($item['payload'] ?? null) ? $item['payload'] : [],
                    'score' => (float) ($item['score'] ?? 0),
                    'generated_at' => now(),
                ]);
        }

        return [
            'summary' => $summary,
            'recommendations' => $created,
        ];
    }

    /**
     * Build panel payload.
     *
     * @return array<string, mixed>
     */
    public function panelData(Lead $lead): array
    {
        $summary = AiSummary::query()
            ->withoutTenancy()
            ->where('tenant_id', (int) $lead->tenant_id)
            ->where('lead_id', (int) $lead->id)
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->first();

        $recommendations = AiRecommendation::query()
            ->withoutTenancy()
            ->where('tenant_id', (int) $lead->tenant_id)
            ->where('lead_id', (int) $lead->id)
            ->orderByDesc('score')
            ->orderByDesc('generated_at')
            ->limit(5)
            ->get();

        return [
            'summary' => $summary,
            'recommendations' => $recommendations,
            'last_generated_at' => $summary?->generated_at?->toIso8601String(),
            'provider' => $summary?->model,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(Lead $lead): array
    {
        $activities = $lead->activities()
            ->withoutTenancy()
            ->where('tenant_id', (int) $lead->tenant_id)
            ->latest('id')
            ->limit(25)
            ->get(['type', 'description', 'properties', 'created_at'])
            ->map(static fn ($activity): array => [
                'type' => (string) $activity->type,
                'description' => (string) ($activity->description ?? ''),
                'properties' => is_array($activity->properties) ? $activity->properties : [],
                'created_at' => optional($activity->created_at)->toIso8601String(),
            ])
            ->values()
            ->all();

        $messages = Message::query()
            ->withoutTenancy()
            ->where('tenant_id', (int) $lead->tenant_id)
            ->where('lead_id', (int) $lead->id)
            ->latest('id')
            ->limit(20)
            ->get(['direction', 'channel', 'subject', 'content', 'status', 'created_at'])
            ->map(static fn (Message $message): array => [
                'direction' => (string) $message->direction,
                'channel' => (string) $message->channel,
                'subject' => (string) ($message->subject ?? ''),
                'content' => mb_substr((string) ($message->content ?? ''), 0, 1500),
                'status' => (string) ($message->status ?? ''),
                'created_at' => optional($message->created_at)->toIso8601String(),
            ])
            ->values()
            ->all();

        return [
            'lead' => [
                'id' => (int) $lead->id,
                'name' => trim(($lead->first_name ?? '').' '.($lead->last_name ?? '')),
                'email' => $lead->email,
                'phone' => $lead->phone,
                'company' => $lead->company,
                'status' => $lead->status,
                'source' => $lead->source,
                'score' => (int) $lead->score,
            ],
            'activities' => $activities,
            'messages' => $messages,
        ];
    }

    private function enforceRateLimit(int $tenantId): void
    {
        $max = max(1, (int) config('features.ai.max_requests_per_minute', 30));
        $key = 'copilot:tenant:'.$tenantId.':'.now()->format('YmdHi');
        $count = (int) Cache::increment($key);

        if ($count === 1) {
            Cache::put($key, 1, now()->addMinute());
        }

        if ($count > $max) {
            abort(429, 'AI rate limit exceeded for this tenant.');
        }
    }
}
