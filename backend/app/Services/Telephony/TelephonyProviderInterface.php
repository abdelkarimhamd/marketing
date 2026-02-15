<?php

namespace App\Services\Telephony;

use App\Models\Call;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;

interface TelephonyProviderInterface
{
    /**
     * Start outbound call.
     *
     * @return array{provider:string,provider_call_id:string,status:string,meta:array<string,mixed>}
     */
    public function startCall(Tenant $tenant, User $user, Lead $lead, array $payload = []): array;

    /**
     * Generate access token for SDK clients.
     *
     * @return array{token:string,expires_at:string,provider:string}
     */
    public function issueAccessToken(Tenant $tenant, User $user): array;

    /**
     * Handle provider webhook and return mapped call updates.
     *
     * @return array{provider_call_id:string,status:string,duration:int|null,recording_url:string|null,meta:array<string,mixed>}|null
     */
    public function mapWebhookPayload(Tenant $tenant, array $payload): ?array;

    /**
     * Provider key.
     */
    public function key(): string;
}
