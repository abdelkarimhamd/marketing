<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\Tenant;
use App\Services\AppointmentBookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    /**
     * List appointments in active tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'leads.view');

        $tenantId = $this->resolveTenantIdStrict($request);
        $filters = $request->validate([
            'lead_id' => ['nullable', 'integer', 'min:1'],
            'owner_id' => ['nullable', 'integer', 'min:1'],
            'team_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:40'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Appointment::query()
            ->withoutTenancy()
            ->with(['lead:id,tenant_id,first_name,last_name,email,status', 'owner:id,name,email', 'team:id,name'])
            ->where('tenant_id', $tenantId)
            ->orderByDesc('starts_at')
            ->orderByDesc('id');

        if (is_numeric($filters['lead_id'] ?? null)) {
            $query->where('lead_id', (int) $filters['lead_id']);
        }

        if (is_numeric($filters['owner_id'] ?? null)) {
            $query->where('owner_id', (int) $filters['owner_id']);
        }

        if (is_numeric($filters['team_id'] ?? null)) {
            $query->where('team_id', (int) $filters['team_id']);
        }

        if (is_string($filters['status'] ?? null) && trim((string) $filters['status']) !== '') {
            $query->where('status', trim((string) $filters['status']));
        }

        if (array_key_exists('date_from', $filters)) {
            $query->whereDate('starts_at', '>=', (string) $filters['date_from']);
        }

        if (array_key_exists('date_to', $filters)) {
            $query->whereDate('starts_at', '<=', (string) $filters['date_to']);
        }

        $limit = (int) ($filters['limit'] ?? 100);

        return response()->json([
            'appointments' => $query
                ->limit($limit)
                ->get()
                ->map(fn (Appointment $appointment): array => $this->mapAppointment($appointment))
                ->values(),
        ]);
    }

    /**
     * Book one appointment for an existing lead.
     */
    public function store(Request $request, AppointmentBookingService $bookingService): JsonResponse
    {
        $this->authorizePermission($request, 'leads.update');

        $tenantId = $this->resolveTenantIdStrict($request);
        $payload = $request->validate([
            'lead_id' => ['required', 'integer', 'exists:leads,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'channel' => ['nullable', 'string', 'max:40'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:720'],
            'booking_link' => ['nullable', 'url', 'max:2000'],
            'deal_stage_on_booking' => ['nullable', 'string', 'max:80'],
            'appointment_status' => ['nullable', 'string', 'max:40'],
            'source' => ['nullable', 'string', 'max:40'],
        ]);

        $lead = Lead::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->whereKey((int) $payload['lead_id'])
            ->first();

        if (! $lead instanceof Lead) {
            abort(422, 'Provided lead_id was not found for active tenant.');
        }

        $appointment = $bookingService->bookForLead(
            lead: $lead,
            payload: array_merge($payload, [
                'source' => is_string($payload['source'] ?? null) ? $payload['source'] : 'admin',
            ]),
            actorId: $request->user()?->id,
        );

        return response()->json([
            'message' => 'Appointment booked successfully.',
            'appointment' => $this->mapAppointment(
                $appointment->load(['lead:id,tenant_id,first_name,last_name,email,status', 'owner:id,name,email', 'team:id,name'])
            ),
        ], 201);
    }

    private function resolveTenantIdStrict(Request $request): int
    {
        $tenantId = $this->resolveTenantIdForPermission($request, $request->user());

        if ($tenantId !== null && Tenant::query()->whereKey($tenantId)->exists()) {
            return $tenantId;
        }

        abort(422, 'Tenant context is required.');
    }

    /**
     * @return array<string, mixed>
     */
    private function mapAppointment(Appointment $appointment): array
    {
        return [
            'id' => (int) $appointment->id,
            'tenant_id' => (int) $appointment->tenant_id,
            'lead_id' => $appointment->lead_id !== null ? (int) $appointment->lead_id : null,
            'owner_id' => $appointment->owner_id !== null ? (int) $appointment->owner_id : null,
            'team_id' => $appointment->team_id !== null ? (int) $appointment->team_id : null,
            'created_by' => $appointment->created_by !== null ? (int) $appointment->created_by : null,
            'source' => $appointment->source,
            'channel' => $appointment->channel,
            'status' => $appointment->status,
            'title' => $appointment->title,
            'description' => $appointment->description,
            'starts_at' => optional($appointment->starts_at)?->toIso8601String(),
            'ends_at' => optional($appointment->ends_at)?->toIso8601String(),
            'timezone' => $appointment->timezone,
            'booking_link' => $appointment->booking_link,
            'meeting_url' => $appointment->meeting_url,
            'external_refs' => is_array($appointment->external_refs) ? $appointment->external_refs : [],
            'meta' => is_array($appointment->meta) ? $appointment->meta : [],
            'created_at' => optional($appointment->created_at)?->toIso8601String(),
            'updated_at' => optional($appointment->updated_at)?->toIso8601String(),
            'lead' => $appointment->lead ? [
                'id' => (int) $appointment->lead->id,
                'first_name' => $appointment->lead->first_name,
                'last_name' => $appointment->lead->last_name,
                'email' => $appointment->lead->email,
                'status' => $appointment->lead->status,
            ] : null,
            'owner' => $appointment->owner ? [
                'id' => (int) $appointment->owner->id,
                'name' => $appointment->owner->name,
                'email' => $appointment->owner->email,
            ] : null,
            'team' => $appointment->team ? [
                'id' => (int) $appointment->team->id,
                'name' => $appointment->team->name,
            ] : null,
        ];
    }
}
