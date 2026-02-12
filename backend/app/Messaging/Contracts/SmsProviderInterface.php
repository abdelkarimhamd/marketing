<?php

namespace App\Messaging\Contracts;

use App\Messaging\DTO\OutgoingMessageData;
use App\Messaging\DTO\ProviderSendResult;

interface SmsProviderInterface
{
    /**
     * Send an SMS payload.
     */
    public function send(OutgoingMessageData $message): ProviderSendResult;
}
