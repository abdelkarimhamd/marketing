<?php

namespace App\Services\Telephony;

use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;

class NullTelephonyProvider implements TelephonyProviderInterface
{
    public function startCall(Tenant $tenant, User $user, Lead $lead, array $payload = []): array
    {
        $phone = is_string($lead->phone) ? trim($lead->phone) : '';

        return [
            'provider' => $this->key(),
            'provider_call_id' => 'manual_'.uniqid('', true),
            'status' => $phone !== '' ? 'queued' : 'failed',
            'meta' => [
                'mode' => 'manual',
                'dial_target' => $phone,
                'tel_link' => $phone !== '' ? 'tel:'.$phone : null,
                'reason' => $phone === '' ? 'lead_missing_phone' : null,
            ],
        ];
    }

    public function issueAccessToken(Tenant $tenant, User $user): array
    {
        $expiresAt = now()->addMinutes(10);

        return [
            'token' => base64_encode(json_encode([
                'provider' => $this->key(),
                'tenant_id' => (int) $tenant->id,
                'user_id' => (int) $user->id,
                'exp' => $expiresAt->timestamp,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'),
            'expires_at' => $expiresAt->toIso8601String(),
            'provider' => $this->key(),
        ];
    }

    public function mapWebhookPayload(Tenant $tenant, array $payload): ?array
    {
        return null;
    }

    public function key(): string
    {
        return 'manual';
    }
}
