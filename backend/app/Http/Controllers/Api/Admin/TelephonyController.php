<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Call;
use App\Models\Lead;
use App\Services\Telephony\TelephonyManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TelephonyController extends Controller
{
    /**
     * List telephony v2 calls.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'telephony.view');
        $tenantId = $this->tenantId($request);

        $payload = $request->validate([
            'lead_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:40'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Call::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->with(['lead:id,first_name,last_name,email,phone', 'user:id,name,email']);

        if (is_numeric($payload['lead_id'] ?? null) && (int) $payload['lead_id'] > 0) {
            $query->where('lead_id', (int) $payload['lead_id']);
        }

        if (is_string($payload['status'] ?? null) && trim((string) $payload['status']) !== '') {
            $query->where('status', trim((string) $payload['status']));
        }

        return response()->json(
            $query->orderByDesc('id')
                ->paginate((int) ($payload['per_page'] ?? 20))
                ->withQueryString()
        );
    }

    /**
     * Start one outbound call.
     */
    public function start(Request $request, TelephonyManager $telephonyManager): JsonResponse
    {
        $this->authorizePermission($request, 'telephony.create');
        $tenantId = $this->tenantId($request);
        $user = $request->user();

        if ($user === null) {
            abort(401, 'Authentication is required.');
        }

        $payload = $request->validate([
            'lead_id' => ['required', 'integer'],
            'to' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'string', 'max:64'],
            'meta' => ['nullable', 'array'],
        ]);

        $lead = Lead::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->whereKey((int) $payload['lead_id'])
            ->first();

        if (! $lead instanceof Lead) {
            abort(404, 'Lead not found in tenant scope.');
        }

        $provider = $telephonyManager->provider();
        $result = $provider->startCall($lead->tenant, $user, $lead, $payload);

        $call = Call::query()
            ->withoutTenancy()
            ->create([
                'tenant_id' => $tenantId,
                'lead_id' => (int) $lead->id,
                'user_id' => (int) $user->id,
                'direction' => 'outbound',
                'status' => (string) ($result['status'] ?? 'queued'),
                'started_at' => now(),
                'provider' => (string) ($result['provider'] ?? $provider->key()),
                'provider_call_id' => (string) ($result['provider_call_id'] ?? ''),
                'meta' => is_array($result['meta'] ?? null) ? $result['meta'] : [],
            ]);

        Activity::query()
            ->withoutTenancy()
            ->create([
                'tenant_id' => $tenantId,
                'actor_id' => (int) $user->id,
                'type' => 'lead.call.started',
                'subject_type' => Lead::class,
                'subject_id' => (int) $lead->id,
                'description' => 'Telephony call started.',
                'properties' => [
                    'call_id' => (int) $call->id,
                    'provider' => $call->provider,
                    'provider_call_id' => $call->provider_call_id,
                    'status' => $call->status,
                ],
            ]);

        return response()->json([
            'message' => 'Call started.',
            'call' => $call->load(['lead:id,first_name,last_name,email,phone', 'user:id,name,email']),
        ], 201);
    }

    /**
     * Update call disposition/outcome.
     */
    public function disposition(Request $request, Call $call): JsonResponse
    {
        $this->authorizePermission($request, 'telephony.update');
        $tenantId = $this->tenantId($request);

        if ((int) $call->tenant_id !== $tenantId) {
            abort(404, 'Call not found in tenant scope.');
        }

        $payload = $request->validate([
            'status' => ['nullable', Rule::in(['queued', 'ringing', 'in_progress', 'completed', 'failed'])],
            'disposition' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'duration' => ['nullable', 'integer', 'min:0'],
            'recording_url' => ['nullable', 'string', 'max:2000'],
        ]);

        $status = isset($payload['status']) ? (string) $payload['status'] : $call->status;
        $isTerminal = in_array($status, ['completed', 'failed'], true);

        $call->forceFill([
            'status' => $status,
            'disposition' => array_key_exists('disposition', $payload) ? $payload['disposition'] : $call->disposition,
            'notes' => array_key_exists('notes', $payload) ? $payload['notes'] : $call->notes,
            'duration' => array_key_exists('duration', $payload) ? (int) $payload['duration'] : $call->duration,
            'recording_url' => array_key_exists('recording_url', $payload) ? $payload['recording_url'] : $call->recording_url,
            'ended_at' => $isTerminal ? now() : $call->ended_at,
        ])->save();

        if ($call->lead_id !== null) {
            Activity::query()->withoutTenancy()->create([
                'tenant_id' => $tenantId,
                'actor_id' => $request->user()?->id,
                'type' => 'lead.call.updated',
                'subject_type' => Lead::class,
                'subject_id' => (int) $call->lead_id,
                'description' => 'Telephony call updated.',
                'properties' => [
                    'call_id' => (int) $call->id,
                    'status' => $call->status,
                    'disposition' => $call->disposition,
                    'duration' => $call->duration,
                ],
            ]);
        }

        return response()->json([
            'message' => 'Call updated successfully.',
            'call' => $call->refresh(),
        ]);
    }

    /**
     * Issue telephony client token.
     */
    public function accessToken(Request $request, TelephonyManager $telephonyManager): JsonResponse
    {
        $this->authorizePermission($request, 'telephony.view');
        $tenantId = $this->tenantId($request);
        $user = $request->user();

        if ($user === null) {
            abort(401, 'Authentication is required.');
        }

        $tenant = $user->tenant;

        if ($tenant === null || (int) $tenant->id !== $tenantId) {
            $tenant = \App\Models\Tenant::query()->whereKey($tenantId)->first();
        }

        if (! $tenant instanceof \App\Models\Tenant) {
            abort(404, 'Tenant not found.');
        }

        try {
            $token = $telephonyManager->provider()->issueAccessToken($tenant, $user);
        } catch (\Throwable $exception) {
            return response()->json([
                'provider' => $telephonyManager->provider()->key(),
                'enabled' => (bool) config('features.telephony.enabled', false),
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json($token);
    }

    private function tenantId(Request $request): int
    {
        $tenantId = $this->resolveTenantIdForPermission($request, $request->user());

        if ($tenantId === null || $tenantId <= 0) {
            abort(422, 'Tenant context is required.');
        }

        return $tenantId;
    }
}
