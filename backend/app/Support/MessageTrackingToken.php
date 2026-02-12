<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class MessageTrackingToken
{
    /**
     * Build signed open pixel token.
     */
    public function makeOpenToken(
        int $tenantId,
        int $messageId,
        ?CarbonInterface $expiresAt = null
    ): string {
        return $this->makeToken(
            tenantId: $tenantId,
            messageId: $messageId,
            action: 'open',
            targetUrl: null,
            expiresAt: $expiresAt
        );
    }

    /**
     * Build signed click tracking token.
     */
    public function makeClickToken(
        int $tenantId,
        int $messageId,
        string $targetUrl,
        ?CarbonInterface $expiresAt = null
    ): string {
        return $this->makeToken(
            tenantId: $tenantId,
            messageId: $messageId,
            action: 'click',
            targetUrl: $targetUrl,
            expiresAt: $expiresAt
        );
    }

    /**
     * Parse and validate tracking token.
     *
     * @return array<string, mixed>|null
     */
    public function parse(string $token): ?array
    {
        if (! str_contains($token, '.')) {
            return null;
        }

        [$encodedPayload, $signature] = explode('.', $token, 2);

        if (! hash_equals(
            hash_hmac('sha256', $encodedPayload, $this->signingKey()),
            $signature
        )) {
            return null;
        }

        $decodedPayload = $this->base64UrlDecode($encodedPayload);

        if ($decodedPayload === null) {
            return null;
        }

        $payload = json_decode($decodedPayload, true);

        if (! is_array($payload)) {
            return null;
        }

        if (! isset($payload['exp']) || CarbonImmutable::now()->timestamp > (int) $payload['exp']) {
            return null;
        }

        if (! in_array(($payload['action'] ?? null), ['open', 'click'], true)) {
            return null;
        }

        return $payload;
    }

    /**
     * Create signed token for one tracking action.
     */
    private function makeToken(
        int $tenantId,
        int $messageId,
        string $action,
        ?string $targetUrl,
        ?CarbonInterface $expiresAt
    ): string {
        $payload = [
            'tenant_id' => $tenantId,
            'message_id' => $messageId,
            'action' => $action,
            'url' => $targetUrl,
            'exp' => (
                $expiresAt
                ?? CarbonImmutable::now()->addDays(
                    max(1, (int) config('messaging.tracking.token_ttl_days', 60))
                )
            )->timestamp,
        ];

        $encodedPayload = $this->base64UrlEncode(
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $signature = hash_hmac('sha256', $encodedPayload, $this->signingKey());

        return $encodedPayload.'.'.$signature;
    }

    /**
     * Resolve signing key from APP_KEY.
     */
    private function signingKey(): string
    {
        $key = (string) config('app.key', '');

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $key;
    }

    /**
     * Base64 URL-safe encode helper.
     */
    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * Base64 URL-safe decode helper.
     */
    private function base64UrlDecode(string $value): ?string
    {
        $padding = strlen($value) % 4;

        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
