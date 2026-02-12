<?php

namespace App\Jobs;

use App\Models\Activity;
use App\Models\Campaign;
use App\Models\CampaignStep;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Template;
use App\Services\CampaignEngineService;
use App\Services\SegmentEvaluationService;
use App\Services\VariableRenderingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class GenerateCampaignMessagesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly int $campaignId,
        public readonly ?int $campaignStepId = null
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(
        CampaignEngineService $engine,
        SegmentEvaluationService $segmentService,
        VariableRenderingService $renderingService
    ): void {
        $campaign = Campaign::query()
            ->withoutTenancy()
            ->with(['segment', 'template'])
            ->whereKey($this->campaignId)
            ->first();

        if ($campaign === null || $campaign->segment === null) {
            return;
        }

        if (in_array($campaign->status, [Campaign::STATUS_PAUSED, Campaign::STATUS_COMPLETED], true)) {
            return;
        }

        $step = $this->resolveStep($campaign);
        $template = $this->resolveTemplate($campaign, $step);

        if ($template === null) {
            return;
        }

        $channel = $step?->channel ?: $template->channel ?: $campaign->channel;
        $created = 0;
        $skipped = 0;

        $leadQuery = $segmentService->queryForSegment($campaign->segment)
            ->orderBy('id');

        $leadQuery->chunkById(200, function (Collection $chunk) use (
            $campaign,
            $step,
            $template,
            $channel,
            $engine,
            $renderingService,
            &$created,
            &$skipped
        ): void {
            foreach ($chunk as $lead) {
                if (! $lead instanceof Lead) {
                    continue;
                }

                if ($engine->shouldStopLead($campaign, $lead, $channel)) {
                    $skipped++;
                    continue;
                }

                $to = $this->resolveDestination($lead, $channel);

                if ($to === null) {
                    $skipped++;
                    continue;
                }

                if ($this->messageAlreadyExists($campaign, $lead, $channel, $step)) {
                    $skipped++;
                    continue;
                }

                $rendered = $engine->renderTemplatePayload($template, $lead, $renderingService);

                $message = Message::query()->withoutTenancy()->create([
                    'tenant_id' => $campaign->tenant_id,
                    'campaign_id' => $campaign->id,
                    'campaign_step_id' => $step?->id,
                    'lead_id' => $lead->id,
                    'template_id' => $template->id,
                    'user_id' => $campaign->created_by,
                    'direction' => 'outbound',
                    'status' => 'queued',
                    'channel' => $channel,
                    'to' => $to,
                    'from' => $channel === 'email'
                        ? (string) config('mail.from.address')
                        : 'system',
                    'subject' => $rendered['subject'] ?? null,
                    'body' => $rendered['body'] ?? null,
                    'meta' => $rendered['meta'] ?? null,
                ]);

                SendCampaignMessageJob::dispatch((int) $message->id);
                $created++;
            }
        });

        $campaign->forceFill([
            'status' => Campaign::STATUS_RUNNING,
        ])->save();

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => $campaign->tenant_id,
            'actor_id' => null,
            'type' => 'campaign.messages.generated',
            'subject_type' => Campaign::class,
            'subject_id' => $campaign->id,
            'description' => 'Campaign messages were generated.',
            'properties' => [
                'campaign_step_id' => $step?->id,
                'created_messages' => $created,
                'skipped_messages' => $skipped,
                'channel' => $channel,
            ],
        ]);
    }

    /**
     * Resolve current step, if this is a drip step generation.
     */
    private function resolveStep(Campaign $campaign): ?CampaignStep
    {
        if ($this->campaignStepId === null) {
            return null;
        }

        return CampaignStep::query()
            ->withoutTenancy()
            ->with('template')
            ->where('tenant_id', $campaign->tenant_id)
            ->where('campaign_id', $campaign->id)
            ->whereKey($this->campaignStepId)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Resolve template used for this generation pass.
     */
    private function resolveTemplate(Campaign $campaign, ?CampaignStep $step): ?Template
    {
        if ($step?->template !== null) {
            return $step->template;
        }

        if ($campaign->template !== null) {
            return $campaign->template;
        }

        return null;
    }

    /**
     * Check if campaign/lead message already exists to avoid duplicates.
     */
    private function messageAlreadyExists(Campaign $campaign, Lead $lead, string $channel, ?CampaignStep $step): bool
    {
        $query = Message::query()
            ->withoutTenancy()
            ->where('tenant_id', $campaign->tenant_id)
            ->where('campaign_id', $campaign->id)
            ->where('lead_id', $lead->id)
            ->where('channel', $channel)
            ->where('direction', 'outbound');

        if ($step === null) {
            $query->whereNull('campaign_step_id');
        } else {
            $query->where('campaign_step_id', $step->id);
        }

        return $query->exists();
    }

    /**
     * Resolve recipient destination by channel.
     */
    private function resolveDestination(Lead $lead, string $channel): ?string
    {
        if ($channel === 'email') {
            $value = is_string($lead->email) ? trim($lead->email) : '';

            return $value !== '' ? $value : null;
        }

        $value = is_string($lead->phone) ? trim($lead->phone) : '';

        return $value !== '' ? $value : null;
    }
}
