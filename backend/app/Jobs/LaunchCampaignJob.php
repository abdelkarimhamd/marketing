<?php

namespace App\Jobs;

use App\Models\Activity;
use App\Models\Campaign;
use App\Services\CampaignEngineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class LaunchCampaignJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(public readonly int $campaignId)
    {
    }

    /**
     * Execute the job.
     */
    public function handle(CampaignEngineService $engine): void
    {
        $campaign = Campaign::query()
            ->withoutTenancy()
            ->with(['segment', 'template', 'steps' => fn ($query) => $query->withoutTenancy()->orderBy('step_order')])
            ->whereKey($this->campaignId)
            ->first();

        if ($campaign === null) {
            return;
        }

        if (in_array($campaign->status, [Campaign::STATUS_PAUSED, Campaign::STATUS_COMPLETED], true)) {
            return;
        }

        $startAt = $campaign->start_at instanceof Carbon && $campaign->start_at->isFuture()
            ? $campaign->start_at
            : now();

        $dispatched = 0;

        if ($campaign->isDrip()) {
            $steps = $campaign->steps->filter(fn ($step) => $step->is_active)->values();

            if ($steps->isEmpty()) {
                $steps = $engine->prepareDripSteps($campaign->refresh())
                    ->filter(fn ($step) => $step->is_active)
                    ->values();
            }

            foreach ($steps as $step) {
                $dispatchAt = (clone $startAt)->addMinutes((int) $step->delay_minutes);

                GenerateCampaignMessagesJob::dispatch((int) $campaign->id, (int) $step->id)
                    ->delay($dispatchAt);

                $dispatched++;
            }
        } else {
            GenerateCampaignMessagesJob::dispatch((int) $campaign->id, null)
                ->delay($startAt);

            $dispatched = 1;
        }

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => $campaign->tenant_id,
            'actor_id' => null,
            'type' => 'campaign.launch.dispatched',
            'subject_type' => Campaign::class,
            'subject_id' => $campaign->id,
            'description' => 'Campaign launch jobs dispatched.',
            'properties' => [
                'campaign_type' => $campaign->campaign_type,
                'jobs_dispatched' => $dispatched,
                'start_at' => $startAt->toIso8601String(),
            ],
        ]);
    }
}
