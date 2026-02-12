<?php

namespace App\Messaging\DTO;

use App\Models\Message;

class OutgoingMessageData
{
    /**
     * Create a value object from the persisted message row.
     *
     * @param array<string, mixed>|null $meta
     */
    public function __construct(
        public readonly int $messageId,
        public readonly int $tenantId,
        public readonly string $channel,
        public readonly string $to,
        public readonly ?string $from,
        public readonly ?string $subject,
        public readonly ?string $body,
        public readonly ?string $provider,
        public readonly ?array $meta = null,
    ) {
    }

    /**
     * Build from Eloquent message model.
     */
    public static function fromMessage(Message $message): self
    {
        return new self(
            messageId: (int) $message->id,
            tenantId: (int) $message->tenant_id,
            channel: (string) $message->channel,
            to: (string) $message->to,
            from: $message->from,
            subject: $message->subject,
            body: $message->body,
            provider: $message->provider,
            meta: is_array($message->meta) ? $message->meta : [],
        );
    }
}
