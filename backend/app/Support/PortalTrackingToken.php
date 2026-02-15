<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class PortalTrackingToken
{
    /**
     * Generate signed portal tracking token payload.
     */
    public function make(
        int $tenantId,
        int $leadId,
        string $intent,
        ?CarbonInterface $expiresAt = null
    ): string {
        $payload = [
            'tenant_id' => $tenantId,
            'lead_id' => $leadId,
            'intent' => trim(mb_strtolower($intent)),
            'exp' => ($expiresAt ?? CarbonImmutable::now()->addDays(
                max(1, (int) config('portal.tracking_token_ttl_days', 180))
            ))->timestamp,
        ];

        $encodedPayload = $this->base64UrlEncode(
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $signature = hash_hmac('sha256', $encodedPayload, $this->signingKey());

        return $encodedPayload.'.'.$signature;
    }

    /**
     * Decode and validate one signed portal tracking token.
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

        $tenantId = is_numeric($payload['tenant_id'] ?? null) ? (int) $payload['tenant_id'] : 0;
        $leadId = is_numeric($payload['lead_id'] ?? null) ? (int) $payload['lead_id'] : 0;
        $intent = is_string($payload['intent'] ?? null) ? trim((string) $payload['intent']) : '';
        $expiresAt = is_numeric($payload['exp'] ?? null) ? (int) $payload['exp'] : 0;

        if ($tenantId <= 0 || $leadId <= 0 || $intent === '' || $expiresAt <= 0) {
            return null;
        }

        if (CarbonImmutable::now()->timestamp > $expiresAt) {
            return null;
        }

        return [
            'tenant_id' => $tenantId,
            'lead_id' => $leadId,
            'intent' => $intent,
            'exp' => $expiresAt,
        ];
    }

    /**
     * Resolve signing key from app key config.
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

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

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
