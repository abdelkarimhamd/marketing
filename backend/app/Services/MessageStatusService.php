<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Message;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class MessageStatusService
{
    /**
     * Apply outbound provider dispatch result.
     *
     * @param array<string, mixed> $meta
     */
    public function markDispatched(
        Message $message,
        string $provider,
        ?string $providerMessageId,
        string $status = 'sent',
        ?string $errorMessage = null,
        array $meta = [],
    ): Message {
        $status = $this->normalizeStatus($status);
        $model = Message::query()->withoutTenancy()->whereKey($message->id)->firstOrFail();
        $existingMeta = is_array($model->meta) ? $model->meta : [];

        $fill = [
            'provider' => $provider,
            'provider_message_id' => $providerMessageId ?? $model->provider_message_id,
            'status' => $status,
            'meta' => array_merge($existingMeta, $meta),
        ];

        if ($status === 'sent') {
            $fill['sent_at'] = $model->sent_at ?? now();
            $fill['failed_at'] = null;
            $fill['error_message'] = null;
        } elseif ($status === 'failed') {
            $fill['failed_at'] = now();
            $fill['error_message'] = $errorMessage ?? $model->error_message;
        } elseif ($status === 'delivered') {
            $fill['sent_at'] = $model->sent_at ?? now();
            $fill['delivered_at'] = $model->delivered_at ?? now();
            $fill['error_message'] = null;
        }

        $model->forceFill($fill)->save();

        $this->recordActivity($model, 'message.status.updated', [
            'source' => 'dispatch',
            'provider' => $provider,
            'provider_message_id' => $providerMessageId,
            'status' => $status,
            'error' => $errorMessage,
        ]);

        if ($status === 'sent' || $status === 'delivered') {
            $this->recordActivity($model, 'campaign.message.sent', [
                'provider' => $provider,
                'provider_message_id' => $providerMessageId,
                'status' => $status,
            ]);
        } elseif ($status === 'failed') {
            $this->recordActivity($model, 'campaign.message.failed', [
                'provider' => $provider,
                'provider_message_id' => $providerMessageId,
                'error' => $errorMessage,
            ]);
        }

        return $model->refresh();
    }

    /**
     * Update message status from provider webhook payload.
     *
     * @param array<string, mixed> $meta
     */
    public function applyProviderStatus(
        string $provider,
        string $providerMessageId,
        string $incomingStatus,
        ?int $tenantId = null,
        ?CarbonInterface $occurredAt = null,
        ?string $errorMessage = null,
        array $meta = [],
    ): ?Message {
        $query = Message::query()
            ->withoutTenancy()
            ->where('provider', $provider)
            ->where('provider_message_id', $providerMessageId);

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        $message = $query->first();

        if ($message === null) {
            return null;
        }

        $status = $this->normalizeStatus($incomingStatus);
        $at = $occurredAt ?? now();
        $existingMeta = is_array($message->meta) ? $message->meta : [];

        $fill = [
            'status' => $status,
            'meta' => array_merge($existingMeta, $meta),
        ];

        if ($status === 'sent') {
            $fill['sent_at'] = $message->sent_at ?? $at;
            $fill['error_message'] = null;
        } elseif ($status === 'delivered') {
            $fill['sent_at'] = $message->sent_at ?? $at;
            $fill['delivered_at'] = $message->delivered_at ?? $at;
            $fill['error_message'] = null;
        } elseif ($status === 'opened') {
            $fill['opened_at'] = $message->opened_at ?? $at;
        } elseif ($status === 'clicked') {
            $fill['clicked_at'] = $message->clicked_at ?? $at;
        } elseif ($status === 'read') {
            $fill['read_at'] = $message->read_at ?? $at;
        } elseif ($status === 'failed') {
            $fill['failed_at'] = $message->failed_at ?? $at;
            $fill['error_message'] = $errorMessage ?? $message->error_message;
        }

        $message->forceFill($fill)->save();

        $this->recordActivity($message, 'message.status.webhook.updated', [
            'source' => 'webhook',
            'provider' => $provider,
            'provider_message_id' => $providerMessageId,
            'status' => $status,
            'raw_status' => $incomingStatus,
            'error' => $errorMessage,
        ]);

        return $message->refresh();
    }

    /**
     * Mark message opened from tracking pixel request.
     */
    public function markOpenedFromTracking(Message $message, array $meta = []): Message
    {
        $model = Message::query()->withoutTenancy()->whereKey($message->id)->firstOrFail();
        $existingMeta = is_array($model->meta) ? $model->meta : [];

        $model->forceFill([
            'status' => in_array($model->status, ['clicked', 'read'], true) ? $model->status : 'opened',
            'opened_at' => $model->opened_at ?? now(),
            'meta' => array_merge($existingMeta, ['tracking_open' => $meta]),
        ])->save();

        $this->recordActivity($model, 'message.tracking.opened', [
            'source' => 'tracking',
        ] + $meta);

        return $model->refresh();
    }

    /**
     * Mark message clicked from redirect request.
     */
    public function markClickedFromTracking(Message $message, array $meta = []): Message
    {
        $model = Message::query()->withoutTenancy()->whereKey($message->id)->firstOrFail();
        $existingMeta = is_array($model->meta) ? $model->meta : [];

        $model->forceFill([
            'status' => 'clicked',
            'clicked_at' => $model->clicked_at ?? now(),
            'meta' => array_merge($existingMeta, ['tracking_click' => $meta]),
        ])->save();

        $this->recordActivity($model, 'message.tracking.clicked', [
            'source' => 'tracking',
        ] + $meta);

        return $model->refresh();
    }

    /**
     * Normalize status names across providers.
     */
    private function normalizeStatus(string $status): string
    {
        $normalized = mb_strtolower(trim($status));

        return match ($normalized) {
            'queued', 'accepted' => 'queued',
            'sent' => 'sent',
            'delivered' => 'delivered',
            'open', 'opened' => 'opened',
            'click', 'clicked' => 'clicked',
            'read' => 'read',
            'bounce', 'bounced', 'undelivered', 'failed', 'error', 'rejected' => 'failed',
            default => $normalized !== '' ? $normalized : 'queued',
        };
    }

    /**
     * Parse mixed timestamp format from webhook payload.
     */
    public function parseOccurredAt(mixed $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return Carbon::createFromTimestamp((int) $value);
        }

        if (is_string($value)) {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Create a status activity on message.
     *
     * @param array<string, mixed> $properties
     */
    private function recordActivity(Message $message, string $type, array $properties = []): void
    {
        Activity::query()->withoutTenancy()->create([
            'tenant_id' => $message->tenant_id,
            'actor_id' => null,
            'type' => $type,
            'subject_type' => Message::class,
            'subject_id' => $message->id,
            'description' => 'Message status lifecycle updated.',
            'properties' => $properties,
        ]);
    }
}
