<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AppointmentBookingService
{
    public function __construct(
        private readonly AppointmentCalendarSyncService $calendarSyncService,
        private readonly RealtimeEventService $eventService,
    ) {
    }

    /**
     * Book one appointment for a lead and sync external calendars.
     *
     * @param array<string, mixed> $payload
     */
    public function bookForLead(Lead $lead, array $payload = [], ?int $actorId = null): Appointment
    {
        $tenantId = (int) $lead->tenant_id;
        $tenant = Tenant::query()->whereKey($tenantId)->first();

        if (! $tenant instanceof Tenant) {
            abort(422, 'Tenant was not found for appointment booking.');
        }

        [$startAt, $endAt, $timezone] = $this->resolveWindow($payload, $lead, $tenant);
        $resolvedOwner = $this->resolveOwner($tenantId, $payload, $lead);
        $resolvedTeam = $this->resolveTeam($tenantId, $payload, $lead);
        $bookingLink = $this->resolveBookingLink($tenant, $resolvedOwner, $resolvedTeam, $payload);
        $dealStageOnBooking = $this->resolveDealStageOnBooking($tenant, $payload);

        return DB::transaction(function () use (
            $tenantId,
            $lead,
            $payload,
            $actorId,
            $startAt,
            $endAt,
            $timezone,
            $resolvedOwner,
            $resolvedTeam,
            $bookingLink,
            $dealStageOnBooking
        ): Appointment {
            $leadModel = Lead::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->whereKey((int) $lead->id)
                ->lockForUpdate()
                ->first();

            if (! $leadModel instanceof Lead) {
                abort(404, 'Lead not found for appointment booking.');
            }

            $previousStatus = (string) $leadModel->status;
            $ownerId = $resolvedOwner?->id ?? $leadModel->owner_id;
            $teamId = $resolvedTeam?->id ?? $leadModel->team_id;

            $appointment = Appointment::query()
                ->withoutTenancy()
                ->create([
                    'tenant_id' => $tenantId,
                    'lead_id' => (int) $leadModel->id,
                    'owner_id' => $ownerId,
                    'team_id' => $teamId,
                    'created_by' => $actorId,
                    'source' => $this->normalizeString($payload['source'] ?? 'portal') ?? 'portal',
                    'channel' => $this->normalizeString($payload['channel'] ?? data_get($leadModel->meta, 'portal.booking.channel')),
                    'status' => $this->normalizeString($payload['appointment_status'] ?? 'booked') ?? 'booked',
                    'title' => $this->normalizeString($payload['title'] ?? null),
                    'description' => $this->normalizeString($payload['description'] ?? data_get($leadModel->meta, 'portal.booking.message')),
                    'starts_at' => $startAt,
                    'ends_at' => $endAt,
                    'timezone' => $timezone,
                    'booking_link' => $bookingLink,
                    'meeting_url' => null,
                    'external_refs' => [],
                    'meta' => [
                        'intent' => $this->normalizeString($payload['intent'] ?? data_get($leadModel->meta, 'portal.intent')),
                        'booked_at' => now()->toIso8601String(),
                    ],
                ]);

            $syncSummary = $this->calendarSyncService->sync($appointment, $leadModel);
            $externalRefs = is_array($syncSummary['external_refs'] ?? null) ? $syncSummary['external_refs'] : [];
            $meetingUrl = $this->normalizeString($syncSummary['meeting_url'] ?? null);

            $appointmentMeta = is_array($appointment->meta) ? $appointment->meta : [];
            $appointmentMeta['calendar_sync'] = is_array($syncSummary['results'] ?? null)
                ? $syncSummary['results']
                : [];

            $appointment->forceFill([
                'external_refs' => $externalRefs,
                'meeting_url' => $meetingUrl,
                'meta' => $appointmentMeta,
            ])->save();

            $leadMeta = is_array($leadModel->meta) ? $leadModel->meta : [];
            data_set($leadMeta, 'portal.booking.appointment_id', (int) $appointment->id);
            data_set($leadMeta, 'portal.booking.starts_at', $startAt->toIso8601String());
            data_set($leadMeta, 'portal.booking.ends_at', $endAt->toIso8601String());
            data_set($leadMeta, 'portal.booking.timezone', $timezone);
            data_set($leadMeta, 'portal.booking.booking_link', $bookingLink);

            if ($meetingUrl !== null) {
                data_set($leadMeta, 'portal.booking.meeting_url', $meetingUrl);
            }

            $leadUpdates = [
                'owner_id' => $ownerId,
                'team_id' => $teamId,
                'next_follow_up_at' => $startAt,
                'meta' => $leadMeta,
            ];

            if ($dealStageOnBooking !== '' && $previousStatus !== $dealStageOnBooking) {
                $leadUpdates['status'] = $dealStageOnBooking;
            }

            $leadModel->forceFill($leadUpdates)->save();

            Activity::query()->withoutTenancy()->create([
                'tenant_id' => $tenantId,
                'actor_id' => $actorId,
                'type' => 'appointment.booked',
                'subject_type' => Lead::class,
                'subject_id' => (int) $leadModel->id,
                'description' => 'Appointment was booked for the lead.',
                'properties' => [
                    'appointment_id' => (int) $appointment->id,
                    'source' => $appointment->source,
                    'channel' => $appointment->channel,
                    'starts_at' => $startAt->toIso8601String(),
                    'ends_at' => $endAt->toIso8601String(),
                    'timezone' => $timezone,
                    'owner_id' => $ownerId,
                    'team_id' => $teamId,
                    'booking_link' => $bookingLink,
                    'meeting_url' => $meetingUrl,
                ],
            ]);

            if ($dealStageOnBooking !== '' && $previousStatus !== $dealStageOnBooking) {
                Activity::query()->withoutTenancy()->create([
                    'tenant_id' => $tenantId,
                    'actor_id' => $actorId,
                    'type' => 'deal.stage_changed',
                    'subject_type' => Lead::class,
                    'subject_id' => (int) $leadModel->id,
                    'description' => 'Deal stage changed after booking appointment.',
                    'properties' => [
                        'from' => $previousStatus,
                        'to' => $dealStageOnBooking,
                        'appointment_id' => (int) $appointment->id,
                    ],
                ]);
            }

            $this->eventService->emit(
                eventName: 'appointment.booked',
                tenantId: $tenantId,
                subjectType: Lead::class,
                subjectId: (int) $leadModel->id,
                payload: [
                    'appointment_id' => (int) $appointment->id,
                    'owner_id' => $ownerId,
                    'team_id' => $teamId,
                    'source' => $appointment->source,
                    'channel' => $appointment->channel,
                    'starts_at' => $startAt->toIso8601String(),
                    'ends_at' => $endAt->toIso8601String(),
                    'timezone' => $timezone,
                ],
            );

            if ($dealStageOnBooking !== '' && $previousStatus !== $dealStageOnBooking) {
                $this->eventService->emit(
                    eventName: 'deal.stage_changed',
                    tenantId: $tenantId,
                    subjectType: Lead::class,
                    subjectId: (int) $leadModel->id,
                    payload: [
                        'from' => $previousStatus,
                        'to' => $dealStageOnBooking,
                        'appointment_id' => (int) $appointment->id,
                        'trigger' => 'appointment.booked',
                    ],
                );
            }

            return $appointment->refresh();
        });
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{Carbon, Carbon, string}
     */
    private function resolveWindow(array $payload, Lead $lead, Tenant $tenant): array
    {
        $timezone = $this->normalizeString($payload['timezone'] ?? data_get($lead->meta, 'portal.booking.timezone'))
            ?? $this->normalizeString((string) $tenant->timezone)
            ?? (string) config('app.timezone', 'UTC');

        $startsAtRaw = $this->normalizeString($payload['starts_at'] ?? data_get($lead->meta, 'portal.booking.preferred_at'));

        if ($startsAtRaw === null) {
            abort(422, 'starts_at is required for booking.');
        }

        try {
            $startAt = Carbon::parse($startsAtRaw, $timezone);
        } catch (\Throwable) {
            abort(422, 'starts_at is not a valid datetime.');
        }

        $endsAtRaw = $this->normalizeString($payload['ends_at'] ?? null);

        if ($endsAtRaw !== null) {
            try {
                $endAt = Carbon::parse($endsAtRaw, $timezone);
            } catch (\Throwable) {
                $endAt = null;
            }
        } else {
            $endAt = null;
        }

        if (! $endAt instanceof Carbon || $endAt->lessThanOrEqualTo($startAt)) {
            $durationMinutes = is_numeric($payload['duration_minutes'] ?? null)
                ? (int) $payload['duration_minutes']
                : (int) data_get($tenant->settings, 'portal.booking.default_duration_minutes', config('portal.booking.default_duration_minutes', 30));

            $durationMinutes = max(5, min(720, $durationMinutes));
            $endAt = $startAt->copy()->addMinutes($durationMinutes);
        }

        return [$startAt, $endAt, $timezone];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveOwner(int $tenantId, array $payload, Lead $lead): ?User
    {
        $ownerId = is_numeric($payload['owner_id'] ?? null)
            ? (int) $payload['owner_id']
            : ($lead->owner_id !== null ? (int) $lead->owner_id : null);

        if (! is_int($ownerId) || $ownerId <= 0) {
            return null;
        }

        $owner = User::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('is_super_admin', false)
            ->whereKey($ownerId)
            ->first();

        if (! $owner instanceof User) {
            abort(422, 'Provided owner_id was not found for tenant.');
        }

        return $owner;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveTeam(int $tenantId, array $payload, Lead $lead): ?Team
    {
        $teamId = is_numeric($payload['team_id'] ?? null)
            ? (int) $payload['team_id']
            : ($lead->team_id !== null ? (int) $lead->team_id : null);

        if (! is_int($teamId) || $teamId <= 0) {
            return null;
        }

        $team = Team::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereKey($teamId)
            ->first();

        if (! $team instanceof Team) {
            abort(422, 'Provided team_id was not found for tenant.');
        }

        return $team;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveBookingLink(Tenant $tenant, ?User $owner, ?Team $team, array $payload): ?string
    {
        $explicit = $this->normalizeString($payload['booking_link'] ?? null);

        if ($explicit !== null) {
            return $explicit;
        }

        $ownerLink = $owner instanceof User
            ? $this->normalizeString(data_get($owner->settings, 'booking.link'))
            : null;

        if ($ownerLink !== null) {
            return $ownerLink;
        }

        $teamLink = $team instanceof Team
            ? $this->normalizeString(data_get($team->settings, 'booking.link'))
            : null;

        if ($teamLink !== null) {
            return $teamLink;
        }

        $tenantSettings = is_array($tenant->settings) ? $tenant->settings : [];

        $tenantPortalLink = $this->normalizeString(data_get($tenantSettings, 'portal.booking.default_link'));

        if ($tenantPortalLink !== null) {
            return $tenantPortalLink;
        }

        return $this->normalizeString(data_get($tenantSettings, 'bot.appointment.booking_url'));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveDealStageOnBooking(Tenant $tenant, array $payload): string
    {
        $provided = $this->normalizeString($payload['deal_stage_on_booking'] ?? null);

        if ($provided !== null) {
            return $provided;
        }

        $tenantSettings = is_array($tenant->settings) ? $tenant->settings : [];

        return $this->normalizeString(
            data_get($tenantSettings, 'portal.booking.deal_stage_on_booking', config('portal.booking.deal_stage_on_booking', 'demo_booked'))
        ) ?? '';
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
