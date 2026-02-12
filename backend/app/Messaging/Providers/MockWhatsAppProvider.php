<?php

namespace App\Messaging\Providers;

use App\Messaging\Contracts\WhatsAppProviderInterface;
use App\Messaging\DTO\OutgoingMessageData;
use App\Messaging\DTO\ProviderSendResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MockWhatsAppProvider implements WhatsAppProviderInterface
{
    /**
     * Mock WhatsApp sender for local development.
     */
    public function send(OutgoingMessageData $message): ProviderSendResult
    {
        $providerMessageId = 'mock-wa-'.Str::uuid()->toString();

        Log::info('Mock WhatsApp provider accepted message.', [
            'message_id' => $message->messageId,
            'tenant_id' => $message->tenantId,
            'to' => $message->to,
            'provider_message_id' => $providerMessageId,
            'template_name' => $message->meta['template_name'] ?? null,
        ]);

        return ProviderSendResult::accepted(
            provider: 'mock',
            providerMessageId: $providerMessageId,
            status: 'sent',
        );
    }
}
