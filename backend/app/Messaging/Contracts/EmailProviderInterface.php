<?php

namespace App\Messaging\Contracts;

use App\Messaging\DTO\OutgoingMessageData;
use App\Messaging\DTO\ProviderSendResult;

interface EmailProviderInterface
{
    /**
     * Send an email message payload.
     */
    public function send(OutgoingMessageData $message): ProviderSendResult;
}
