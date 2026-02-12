<?php

namespace App\Messaging\Providers;

use App\Messaging\Contracts\SmsProviderInterface;
use App\Messaging\DTO\OutgoingMessageData;
use App\Messaging\DTO\ProviderSendResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MockSmsProvider implements SmsProviderInterface
{
    /**
     * Mock SMS sender for local development.
     */
    public function send(OutgoingMessageData $message): ProviderSendResult
    {
        $providerMessageId = 'mock-sms-'.Str::uuid()->toString();

        Log::info('Mock SMS provider accepted message.', [
            'message_id' => $message->messageId,
            'tenant_id' => $message->tenantId,
            'to' => $message->to,
            'provider_message_id' => $providerMessageId,
        ]);

        return ProviderSendResult::accepted(
            provider: 'mock',
            providerMessageId: $providerMessageId,
            status: 'sent',
        );
    }
}
