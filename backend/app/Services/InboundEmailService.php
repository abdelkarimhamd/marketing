<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Message;
use Illuminate\Support\Str;

class InboundEmailService
{
    public function __construct(
        private readonly ReplyAddressService $replyAddressService,
        private readonly RealtimeEventService $realtimeEventService,
    ) {
    }

    /**
     * Create inbound message row for email reply payloads.
     *
     * @param array<string, mixed> $payload
     */
    public function captureInboundReply(string $provider, array $payload): ?Message
    {
        $sourceAddress = $this->extractAddress($payload['to'] ?? $payload['recipient'] ?? null);
        $replyToken = $this->replyAddressService->tokenFromAddress($sourceAddress);
        $inReplyTo = (string) ($payload['in_reply_to'] ?? $payload['message_id'] ?? '');

        $original = $this->resolveOriginalMessage($replyToken, $inReplyTo);

        if ($original === null) {
            return null;
        }

        $inboundProviderMessageId = (string) ($payload['provider_message_id'] ?? $payload['id'] ?? $payload['message_id'] ?? '');
        $body = (string) ($payload['text'] ?? $payload['body'] ?? $payload['content'] ?? '');
        $subject = (string) ($payload['subject'] ?? 'Re: '.($original->subject ?? ''));

        $message = Message::query()
            ->withoutTenancy()
            ->firstOrCreate(
                [
                    'tenant_id' => $original->tenant_id,
                    'provider' => $provider,
                    'provider_message_id' => $inboundProviderMessageId !== '' ? $inboundProviderMessageId : null,
                ],
                [
                    'brand_id' => $original->brand_id,
                    'campaign_id' => $original->campaign_id,
                    'campaign_step_id' => null,
                    'lead_id' => $original->lead_id,
                    'template_id' => null,
                    'user_id' => null,
                    'direction' => 'inbound',
                    'status' => 'received',
                    'channel' => 'email',
                    'thread_key' => $original->thread_key ?: $this->replyAddressService->threadKey($original),
                    'to' => (string) ($payload['from'] ?? ''),
                    'from' => (string) ($payload['to'] ?? $sourceAddress),
                    'subject' => $subject,
                    'body' => $body,
                    'in_reply_to' => $original->provider_message_id ?: null,
                    'meta' => [
                        'raw' => $payload,
                        'captured_via' => 'webhook',
                    ],
                    'sent_at' => now(),
                ]
            );

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => $message->tenant_id,
            'actor_id' => null,
            'type' => 'message.inbound.reply',
            'subject_type' => Message::class,
            'subject_id' => $message->id,
            'description' => 'Inbound email reply captured and attached to conversation timeline.',
            'properties' => [
                'provider' => $provider,
                'original_message_id' => $original->id,
                'lead_id' => $message->lead_id,
                'campaign_id' => $message->campaign_id,
                'brand_id' => $message->brand_id,
            ],
        ]);

        $this->realtimeEventService->emit(
            eventName: 'inbox.reply.captured',
            tenantId: (int) $message->tenant_id,
            subjectType: Message::class,
            subjectId: (int) $message->id,
            payload: [
                'lead_id' => $message->lead_id,
                'campaign_id' => $message->campaign_id,
                'brand_id' => $message->brand_id,
                'channel' => 'email',
                'direction' => 'inbound',
            ],
        );

        return $message;
    }

    private function resolveOriginalMessage(?string $replyToken, string $inReplyTo): ?Message
    {
        $query = Message::query()->withoutTenancy()->where('channel', 'email')->where('direction', 'outbound');

        if (is_string($replyToken) && $replyToken !== '') {
            $matched = (clone $query)->where('reply_token', $replyToken)->first();

            if ($matched !== null) {
                return $matched;
            }
        }

        if ($inReplyTo !== '') {
            $normalized = trim($inReplyTo, '<> ');
            $matched = (clone $query)
                ->where(function ($builder) use ($normalized): void {
                    $builder
                        ->where('provider_message_id', $normalized)
                        ->orWhere('provider_message_id', Str::lower($normalized));
                })
                ->first();

            if ($matched !== null) {
                return $matched;
            }
        }

        return null;
    }

    private function extractAddress(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/<([^>]+)>/', $value, $matches)) {
            return trim($matches[1]);
        }

        return $value;
    }
}
