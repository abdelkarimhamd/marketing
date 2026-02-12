<?php

namespace App\Messaging\DTO;

class ProviderSendResult
{
    /**
     * Result payload returned by a channel provider.
     *
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $provider,
        public readonly bool $accepted,
        public readonly ?string $providerMessageId = null,
        public readonly string $status = 'sent',
        public readonly ?string $errorMessage = null,
        public readonly array $meta = [],
    ) {
    }

    /**
     * Build an accepted provider response.
     *
     * @param array<string, mixed> $meta
     */
    public static function accepted(
        string $provider,
        ?string $providerMessageId = null,
        string $status = 'sent',
        array $meta = [],
    ): self {
        return new self(
            provider: $provider,
            accepted: true,
            providerMessageId: $providerMessageId,
            status: $status,
            meta: $meta,
        );
    }

    /**
     * Build a failed provider response.
     *
     * @param array<string, mixed> $meta
     */
    public static function failed(string $provider, string $errorMessage, array $meta = []): self
    {
        return new self(
            provider: $provider,
            accepted: false,
            status: 'failed',
            errorMessage: $errorMessage,
            meta: $meta,
        );
    }
}
