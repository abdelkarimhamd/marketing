<?php

namespace App\Jobs;

use App\Messaging\MessageDispatcher;
use App\Models\Campaign;
use App\Models\Message;
use App\Services\MessageStatusService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendCampaignMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(public readonly int $messageId)
    {
    }

    /**
     * Execute the job.
     */
    public function handle(MessageDispatcher $dispatcher, MessageStatusService $statusService): void
    {
        $message = Message::query()
            ->withoutTenancy()
            ->with('campaign')
            ->whereKey($this->messageId)
            ->first();

        if ($message === null) {
            return;
        }

        if ($message->status !== 'queued') {
            return;
        }

        if (! is_string($message->to) || trim($message->to) === '') {
            $statusService->markDispatched(
                message: $message,
                provider: $message->provider ?? 'system',
                providerMessageId: $message->provider_message_id,
                status: 'failed',
                errorMessage: 'No destination for message.',
            );

            $this->maybeCompleteCampaign($message->campaign_id);
            return;
        }

        try {
            $result = $dispatcher->dispatch($message);

            $statusService->markDispatched(
                message: $message,
                provider: $result->provider,
                providerMessageId: $result->providerMessageId,
                status: $result->accepted ? $result->status : 'failed',
                errorMessage: $result->errorMessage,
                meta: $result->meta,
            );
        } catch (Throwable $exception) {
            $statusService->markDispatched(
                message: $message,
                provider: $message->provider ?? 'system',
                providerMessageId: $message->provider_message_id,
                status: 'failed',
                errorMessage: $exception->getMessage(),
            );

            report($exception);
        }

        $this->maybeCompleteCampaign($message->campaign_id);
    }

    /**
     * Mark campaign complete when no queued/running messages remain.
     */
    private function maybeCompleteCampaign(?int $campaignId): void
    {
        if ($campaignId === null) {
            return;
        }

        $campaign = Campaign::query()
            ->withoutTenancy()
            ->whereKey($campaignId)
            ->first();

        if ($campaign === null || ! in_array($campaign->status, [Campaign::STATUS_RUNNING, Campaign::STATUS_SCHEDULED], true)) {
            return;
        }

        $pending = Message::query()
            ->withoutTenancy()
            ->where('tenant_id', $campaign->tenant_id)
            ->where('campaign_id', $campaign->id)
            ->whereIn('status', ['queued'])
            ->exists();

        if (! $pending) {
            $campaign->forceFill([
                'status' => Campaign::STATUS_COMPLETED,
            ])->save();
        }
    }
}
