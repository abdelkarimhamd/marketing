<?php

namespace App\Messaging\Providers;

use App\Messaging\Contracts\EmailProviderInterface;
use App\Messaging\DTO\OutgoingMessageData;
use App\Messaging\DTO\ProviderSendResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MockEmailProvider implements EmailProviderInterface
{
    /**
     * Mock email sender for local development.
     */
    public function send(OutgoingMessageData $message): ProviderSendResult
    {
        $providerMessageId = 'mock-email-'.Str::uuid()->toString();

        Log::info('Mock email provider accepted message.', [
            'message_id' => $message->messageId,
            'tenant_id' => $message->tenantId,
            'to' => $message->to,
            'subject' => $message->subject,
            'provider_message_id' => $providerMessageId,
        ]);

        return ProviderSendResult::accepted(
            provider: 'mock',
            providerMessageId: $providerMessageId,
            status: 'sent',
        );
    }
}
