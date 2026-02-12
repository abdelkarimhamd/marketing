<?php

namespace App\Messaging\Contracts;

use App\Messaging\DTO\OutgoingMessageData;
use App\Messaging\DTO\ProviderSendResult;

interface WhatsAppProviderInterface
{
    /**
     * Send a WhatsApp payload.
     */
    public function send(OutgoingMessageData $message): ProviderSendResult;
}
