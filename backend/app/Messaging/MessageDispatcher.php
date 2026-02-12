<?php

namespace App\Messaging;

use App\Messaging\DTO\OutgoingMessageData;
use App\Messaging\DTO\ProviderSendResult;
use App\Models\Message;
use App\Services\MessageTrackingService;

class MessageDispatcher
{
    public function __construct(
        private readonly ProviderManager $providerManager,
        private readonly MessageTrackingService $trackingService,
    ) {
    }

    /**
     * Send one queued message via its configured channel provider.
     */
    public function dispatch(Message $message): ProviderSendResult
    {
        if ($message->channel === 'email') {
            $message = $this->trackingService->decorateEmailMessage($message);
            $payload = OutgoingMessageData::fromMessage($message);

            return $this->providerManager->emailProvider()->send($payload);
        }

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
