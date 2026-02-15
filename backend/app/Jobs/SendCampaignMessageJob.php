<?php

namespace App\Jobs;

use App\Messaging\MessageDispatcher;
use App\Models\Campaign;
use App\Models\Message;
use App\Services\BillingService;
use App\Services\ComplianceService;
use App\Services\MessageStatusService;
use App\Services\RealtimeEventService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SendCampaignMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 8;

    public int $maxExceptions = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(public readonly int $messageId)
    {
    }

    /**
     * Execute the job.
     */
    public function handle(
        MessageDispatcher $dispatcher,
        MessageStatusService $statusService,
        ?ComplianceService $complianceService = null,
        ?BillingService $billingService = null,
        ?RealtimeEventService $eventService = null
    ): void
    {
        $complianceService ??= app(ComplianceService::class);
        $billingService ??= app(BillingService::class);
        $eventService ??= app(RealtimeEventService::class);

        $message = Message::query()
            ->withoutTenancy()
            ->with(['campaign', 'lead'])
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

        if ($this->shouldThrottle($message)) {
            $this->release($this->adaptiveDelaySeconds());

            return;
        }

        $compliance = $complianceService->evaluate($message);

        if (! $compliance['allowed']) {
            $message->forceFill([
                'status' => 'failed',
                'compliance_block_reason' => $compliance['reason'],
                'error_message' => $compliance['reason'],
                'failed_at' => now(),
            ])->save();

            $statusService->markDispatched(
                message: $message->refresh(),
                provider: 'compliance',
                providerMessageId: $message->provider_message_id,
                status: 'failed',
                errorMessage: $compliance['reason'],
            );

            $eventService->emit(
                eventName: 'message.blocked',
                tenantId: (int) $message->tenant_id,
                subjectType: Message::class,
                subjectId: (int) $message->id,
                payload: [
                    'reason' => $compliance['reason'],
                    'channel' => $message->channel,
                    'lead_id' => $message->lead_id,
                ],
            );

            $this->maybeCompleteCampaign($message->campaign_id);
            return;
        }

        $billingAllowance = $billingService->evaluateMessageAllowance((int) $message->tenant_id, (string) $message->channel);

        if (! $billingAllowance['allowed']) {
            $message->forceFill([
                'status' => 'failed',
                'error_message' => $billingAllowance['reason'],
                'failed_at' => now(),
                'compliance_block_reason' => $billingAllowance['reason'],
            ])->save();

            $statusService->markDispatched(
                message: $message->refresh(),
                provider: 'billing',
                providerMessageId: $message->provider_message_id,
                status: 'failed',
                errorMessage: $billingAllowance['reason'],
            );

            $this->maybeCompleteCampaign($message->campaign_id);
            return;
        }

        $existingMeta = is_array($message->meta) ? $message->meta : [];

        $message->forceFill([
            'from' => $compliance['from'] ?: $message->from,
            'meta' => array_replace_recursive($existingMeta, [
                'billing' => [
                    'overage' => (bool) ($billingAllowance['overage'] ?? false),
                    'overage_amount' => (float) ($billingAllowance['cost_estimate'] ?? 0),
                ],
            ]),
        ])->save();

        try {
            $result = $dispatcher->dispatch($message->refresh());

            $finalStatus = $result->accepted ? $result->status : 'failed';
            $error = $result->errorMessage;

            if (! $result->accepted && $this->shouldRetryFromError($error) && $this->attempts() < 8) {
                $this->release($this->adaptiveDelaySeconds());

                return;
            }

            $updated = $statusService->markDispatched(
                message: $message,
                provider: $result->provider,
                providerMessageId: $result->providerMessageId,
                status: $finalStatus,
                errorMessage: $error,
                meta: $result->meta,
            );

            if (in_array($finalStatus, ['sent', 'delivered', 'opened', 'clicked', 'read'], true)) {
                $billingService->trackDispatchedMessage($updated);
            }

            $eventService->emit(
                eventName: 'campaign.message.'.$finalStatus,
                tenantId: (int) $updated->tenant_id,
                subjectType: Message::class,
                subjectId: (int) $updated->id,
                payload: [
                    'campaign_id' => $updated->campaign_id,
                    'lead_id' => $updated->lead_id,
                    'channel' => $updated->channel,
                ],
            );
        } catch (Throwable $exception) {
            if ($this->shouldRetryFromError($exception->getMessage()) && $this->attempts() < 8) {
                $this->release($this->adaptiveDelaySeconds());

                return;
            }

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
     * Simple per-tenant/channel minute throttle.
     */
    private function shouldThrottle(Message $message): bool
    {
        $limit = (int) config('messaging.rate_limits.'.$message->channel, 240);

        if ($limit <= 0) {
            return false;
        }

        $bucket = now()->format('YmdHi');
        $key = sprintf(
            'message-send-rate:%d:%s:%s',
            (int) $message->tenant_id,
            (string) $message->channel,
            $bucket
        );

        $existing = Cache::get($key);

        if ($existing === null) {
            Cache::put($key, 1, now()->addMinute());
            $count = 1;
        } else {
            $incremented = Cache::increment($key);
            $count = is_numeric($incremented) ? (int) $incremented : (int) Cache::get($key, 0);
        }

        return $count > $limit;
    }

    /**
     * Adaptive retry delay in seconds based on attempts.
     */
    private function adaptiveDelaySeconds(): int
    {
        return min(300, 5 * (2 ** max(0, $this->attempts() - 1)));
    }

    /**
     * Retry only known transient errors.
     */
    private function shouldRetryFromError(?string $error): bool
    {
        if (! is_string($error) || trim($error) === '') {
            return false;
        }

        $message = mb_strtolower($error);

        return str_contains($message, 'rate limit')
            || str_contains($message, 'timeout')
            || str_contains($message, 'temporarily unavailable')
            || str_contains($message, 'connection reset');
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
