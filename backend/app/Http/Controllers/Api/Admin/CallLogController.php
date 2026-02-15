<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\CallLog;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CallLogController extends Controller
{
    /**
     * List call logs in tenant scope.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'leads.view');

        $payload = $request->validate([
            'lead_id' => ['nullable', 'integer', 'exists:leads,id'],
            'status' => ['nullable', 'string', 'max:24'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = CallLog::query()->with(['lead:id,first_name,last_name,email,phone', 'user:id,name,email']);

        if (! empty($payload['lead_id'])) {
            $query->where('lead_id', (int) $payload['lead_id']);
        }

        if (! empty($payload['status'])) {
            $query->where('status', $payload['status']);
        }

        return response()->json(
            $query->orderByDesc('id')
                ->paginate((int) ($payload['per_page'] ?? 20))
                ->withQueryString()
        );
    }

    /**
     * Create new click-to-call log.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'leads.update');

        $tenantId = $this->tenantId($request);
        $payload = $request->validate([
            'lead_id' => ['nullable', 'integer', 'exists:leads,id'],
            'provider' => ['nullable', 'string', 'max:64'],
            'provider_call_id' => ['nullable', 'string', 'max:255'],
            'direction' => ['nullable', Rule::in(['outbound', 'inbound'])],
            'status' => ['nullable', Rule::in(['queued', 'ringing', 'in_progress', 'completed', 'failed'])],
            'notes' => ['nullable', 'string', 'max:4000'],
            'next_follow_up_at' => ['nullable', 'date'],
            'meta' => ['nullable', 'array'],
        ]);

        $call = CallLog::query()->withoutTenancy()->create([
            'tenant_id' => $tenantId,
            'lead_id' => $payload['lead_id'] ?? null,
            'user_id' => $request->user()?->id,
            'provider' => $payload['provider'] ?? null,
            'provider_call_id' => $payload['provider_call_id'] ?? null,
            'direction' => $payload['direction'] ?? 'outbound',
            'status' => $payload['status'] ?? 'queued',
            'notes' => $payload['notes'] ?? null,
            'next_follow_up_at' => $payload['next_follow_up_at'] ?? null,
            'meta' => $payload['meta'] ?? [],
            'started_at' => now(),
        ]);

        return response()->json([
            'message' => 'Call log created.',
            'call' => $call,
        ], 201);
    }

    /**
     * Mark call completed and create lead activity.
     */
    public function complete(Request $request, CallLog $callLog): JsonResponse
    {
        $this->authorizePermission($request, 'leads.update');
        $tenantId = $this->tenantId($request);

        if ((int) $callLog->tenant_id !== $tenantId) {
            abort(404, 'Call log not found in tenant scope.');
        }

        $payload = $request->validate([
            'status' => ['nullable', Rule::in(['completed', 'failed'])],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'outcome' => ['nullable', 'string', 'max:120'],
            'recording_url' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'next_follow_up_at' => ['nullable', 'date'],
        ]);

        DB::transaction(function () use ($request, $callLog, $payload): void {
            $callLog->forceFill([
                'status' => $payload['status'] ?? 'completed',
                'duration_seconds' => $payload['duration_seconds'] ?? $callLog->duration_seconds,
                'outcome' => $payload['outcome'] ?? $callLog->outcome,
                'recording_url' => $payload['recording_url'] ?? $callLog->recording_url,
                'notes' => $payload['notes'] ?? $callLog->notes,
                'next_follow_up_at' => $payload['next_follow_up_at'] ?? $callLog->next_follow_up_at,
                'ended_at' => now(),
            ])->save();

            if ($callLog->lead_id !== null) {
                $lead = Lead::query()->withoutTenancy()->whereKey($callLog->lead_id)->first();

                if ($lead !== null) {
                    Activity::query()->withoutTenancy()->create([
                        'tenant_id' => $callLog->tenant_id,
                        'actor_id' => $request->user()?->id,
                        'type' => 'lead.call.completed',
                        'subject_type' => Lead::class,
                        'subject_id' => $lead->id,
                        'description' => 'Call completed and logged.',
                        'properties' => [
                            'call_log_id' => $callLog->id,
                            'status' => $callLog->status,
                            'outcome' => $callLog->outcome,
                            'duration_seconds' => $callLog->duration_seconds,
                            'recording_url' => $callLog->recording_url,
                        ],
                    ]);
                }
            }
        });

        return response()->json([
            'message' => 'Call log updated.',
            'call' => $callLog->refresh(),
        ]);
    }

    private function tenantId(Request $request): int
    {
        $tenantId = $request->attributes->get('tenant_id');

        if (is_int($tenantId) && $tenantId > 0) {
            return $tenantId;
        }

        $requested = $request->query('tenant_id', $request->input('tenant_id'));

        if (is_numeric($requested) && (int) $requested > 0) {
            return (int) $requested;
        }

        abort(422, 'Tenant context is required.');
    }
}

