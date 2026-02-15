<?php

namespace App\Messaging;

use App\Messaging\DTO\OutgoingMessageData;
use App\Messaging\DTO\ProviderSendResult;
use App\Models\Message;
use App\Services\ChannelFormattingService;
use App\Services\MessageTrackingService;
use App\Services\ReplyAddressService;

class MessageDispatcher
{
    public function __construct(
        private readonly ProviderManager $providerManager,
        private readonly MessageTrackingService $trackingService,
        private readonly ReplyAddressService $replyAddressService,
        private readonly ChannelFormattingService $formattingService,
    ) {
    }

    /**
     * Send one queued message via its configured channel provider.
     */
    public function dispatch(Message $message): ProviderSendResult
    {
        $message = $message->relationLoaded('lead') ? $message : $message->loadMissing('lead');
        $locale = $message->lead?->locale;

        if ($message->channel === 'email') {
            $message = $this->replyAddressService->ensureReplyMetadata($message);
            $meta = is_array($message->meta) ? $message->meta : [];
            $meta['reply_to_email'] = $message->reply_to_email;
            $message->forceFill([
                'body' => $this->formattingService->formatContent('email', $message->body, $locale),
                'meta' => $meta,
            ])->save();

            $message = $this->trackingService->decorateEmailMessage($message);
            $payload = OutgoingMessageData::fromMessage($message);

            return $this->providerManager->emailProvider((int) $message->tenant_id)->send($payload);
        }

        $message->forceFill([
            'body' => $this->formattingService->formatContent($message->channel, $message->body, $locale),
        ])->save();

        $payload = OutgoingMessageData::fromMessage($message);

        if ($message->channel === 'sms') {
            return $this->providerManager->smsProvider()->send($payload);
        }

        if ($message->channel === 'whatsapp') {
            return $this->providerManager->whatsappProvider()->send($payload);
        }

        return ProviderSendResult::failed('system', "Unsupported channel '{$message->channel}'.");
    }
}
