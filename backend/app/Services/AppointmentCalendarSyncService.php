<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\IntegrationConnection;
use App\Models\Lead;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class AppointmentCalendarSyncService
{
    /**
     * Sync one appointment to configured tenant calendar integrations.
     *
     * @return array{
     *     results: list<array<string, mixed>>,
     *     external_refs: array<string, array<string, mixed>>,
     *     meeting_url: string|null
     * }
     */
    public function sync(Appointment $appointment, Lead $lead): array
    {
        $connections = IntegrationConnection::query()
            ->withoutTenancy()
            ->where('tenant_id', (int) $appointment->tenant_id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $results = [];
        $externalRefs = [];
        $meetingUrl = null;

        foreach ($connections as $connection) {
            $provider = $this->canonicalProvider((string) $connection->provider);

            if ($provider === null) {
                continue;
            }

            try {
                $result = $provider === 'google'
                    ? $this->syncGoogle($appointment, $lead, $connection)
                    : $this->syncMicrosoft($appointment, $lead, $connection);
            } catch (\Throwable $exception) {
                $result = [
                    'provider' => $provider,
                    'connection_id' => (int) $connection->id,
                    'status' => 'failed',
                    'error' => $exception->getMessage(),
                ];
            }

            $results[] = $result;

            if (($result['status'] ?? null) === 'synced') {
                $externalRefs[$provider] = [
                    'connection_id' => (int) $connection->id,
                    'event_id' => $result['event_id'] ?? null,
                    'calendar_id' => $result['calendar_id'] ?? null,
                    'provider' => $provider,
                    'synced_at' => now()->toIso8601String(),
                ];

                if ($meetingUrl === null && is_string($result['meeting_url'] ?? null) && trim((string) $result['meeting_url']) !== '') {
                    $meetingUrl = trim((string) $result['meeting_url']);
                }
            }
        }

        return [
            'results' => $results,
            'external_refs' => $externalRefs,
            'meeting_url' => $meetingUrl,
        ];
    }

    /**
     * Push one appointment to Google Calendar API.
     *
     * @return array<string, mixed>
     */
    private function syncGoogle(Appointment $appointment, Lead $lead, IntegrationConnection $connection): array
    {
        $config = is_array($connection->config) ? $connection->config : [];
        $secrets = is_array($connection->secrets) ? $connection->secrets : [];
        $token = trim((string) ($secrets['access_token'] ?? $secrets['token'] ?? ''));

        if ($token === '') {
            $this->markConnectionError($connection, 'Missing Google Calendar access token.');

            return [
                'provider' => 'google',
                'connection_id' => (int) $connection->id,
                'status' => 'failed',
                'error' => 'missing_access_token',
            ];
        }

        [$startAt, $endAt, $timezone] = $this->resolveAppointmentWindow($appointment);
        $calendarId = trim((string) ($config['calendar_id'] ?? 'primary'));
        $calendarId = $calendarId !== '' ? $calendarId : 'primary';
        $baseUrl = rtrim((string) ($config['base_url'] ?? 'https://www.googleapis.com'), '/');

        $payload = [
            'summary' => $this->appointmentTitle($appointment, $lead),
            'description' => $this->appointmentDescription($appointment, $lead),
            'start' => [
                'dateTime' => $startAt->toIso8601String(),
                'timeZone' => $timezone,
            ],
            'end' => [
                'dateTime' => $endAt->toIso8601String(),
                'timeZone' => $timezone,
            ],
            'attendees' => $this->attendeesForLead($lead),
        ];

        $client = Http::timeout(12)
            ->withToken($token)
            ->acceptJson();

        if ((bool) ($config['create_meeting_link'] ?? false)) {
            $client = $client->withQueryParameters(['conferenceDataVersion' => 1]);
            $payload['conferenceData'] = [
                'createRequest' => [
                    'requestId' => sprintf('appt-%d-%s', (int) $appointment->id, now()->timestamp),
                ],
            ];
        }

        $response = $client->post(
            sprintf('%s/calendar/v3/calendars/%s/events', $baseUrl, rawurlencode($calendarId)),
            $payload,
        );

        if (! $response->successful()) {
            $error = sprintf('HTTP %d: %s', $response->status(), $response->body());
            $this->markConnectionError($connection, $error);

            return [
                'provider' => 'google',
                'connection_id' => (int) $connection->id,
                'status' => 'failed',
                'calendar_id' => $calendarId,
                'error' => $error,
            ];
        }

        $body = $response->json();
        $eventId = is_string($body['id'] ?? null) ? $body['id'] : null;
        $meetingUrl = is_string($body['hangoutLink'] ?? null)
            ? $body['hangoutLink']
            : (is_string($body['htmlLink'] ?? null) ? $body['htmlLink'] : null);

        $this->markConnectionSynced($connection);

        return [
            'provider' => 'google',
            'connection_id' => (int) $connection->id,
            'status' => 'synced',
            'calendar_id' => $calendarId,
            'event_id' => $eventId,
            'meeting_url' => $meetingUrl,
        ];
    }

    /**
     * Push one appointment to Microsoft Graph calendar API.
     *
     * @return array<string, mixed>
     */
    private function syncMicrosoft(Appointment $appointment, Lead $lead, IntegrationConnection $connection): array
    {
        $config = is_array($connection->config) ? $connection->config : [];
        $secrets = is_array($connection->secrets) ? $connection->secrets : [];
        $token = trim((string) ($secrets['access_token'] ?? $secrets['token'] ?? ''));

        if ($token === '') {
            $this->markConnectionError($connection, 'Missing Microsoft Graph access token.');

            return [
                'provider' => 'microsoft',
                'connection_id' => (int) $connection->id,
                'status' => 'failed',
                'error' => 'missing_access_token',
            ];
        }

        [$startAt, $endAt, $timezone] = $this->resolveAppointmentWindow($appointment);
        $calendarId = trim((string) ($config['calendar_id'] ?? ''));
        $baseUrl = rtrim((string) ($config['base_url'] ?? 'https://graph.microsoft.com/v1.0'), '/');
        $endpoint = $calendarId !== ''
            ? sprintf('%s/me/calendars/%s/events', $baseUrl, rawurlencode($calendarId))
            : sprintf('%s/me/events', $baseUrl);

        $payload = [
            'subject' => $this->appointmentTitle($appointment, $lead),
            'body' => [
                'contentType' => 'text',
                'content' => $this->appointmentDescription($appointment, $lead),
            ],
            'start' => [
                'dateTime' => $startAt->format('Y-m-d\TH:i:s'),
                'timeZone' => $timezone,
            ],
            'end' => [
                'dateTime' => $endAt->format('Y-m-d\TH:i:s'),
                'timeZone' => $timezone,
            ],
            'attendees' => $this->attendeesForMicrosoft($lead),
        ];

        if ((bool) ($config['is_online_meeting'] ?? false)) {
            $payload['isOnlineMeeting'] = true;
            $payload['onlineMeetingProvider'] = (string) ($config['online_meeting_provider'] ?? 'teamsForBusiness');
        }

        $response = Http::timeout(12)
            ->withToken($token)
            ->acceptJson()
            ->post($endpoint, $payload);

        if (! $response->successful()) {
            $error = sprintf('HTTP %d: %s', $response->status(), $response->body());
            $this->markConnectionError($connection, $error);

            return [
                'provider' => 'microsoft',
                'connection_id' => (int) $connection->id,
                'status' => 'failed',
                'calendar_id' => $calendarId !== '' ? $calendarId : null,
                'error' => $error,
            ];
        }

        $body = $response->json();
        $eventId = is_string($body['id'] ?? null) ? $body['id'] : null;
        $meetingUrl = is_string(data_get($body, 'onlineMeeting.joinUrl'))
            ? (string) data_get($body, 'onlineMeeting.joinUrl')
            : (is_string($body['webLink'] ?? null) ? $body['webLink'] : null);

        $this->markConnectionSynced($connection);

        return [
            'provider' => 'microsoft',
            'connection_id' => (int) $connection->id,
            'status' => 'synced',
            'calendar_id' => $calendarId !== '' ? $calendarId : null,
            'event_id' => $eventId,
            'meeting_url' => $meetingUrl,
        ];
    }

    /**
     * @return array{Carbon, Carbon, string}
     */
    private function resolveAppointmentWindow(Appointment $appointment): array
    {
        $timezone = is_string($appointment->timezone) && trim((string) $appointment->timezone) !== ''
            ? trim((string) $appointment->timezone)
            : (string) config('app.timezone', 'UTC');

        $startAt = $appointment->starts_at instanceof Carbon
            ? $appointment->starts_at->copy()
            : now()->addHour();

        $endAt = $appointment->ends_at instanceof Carbon
            ? $appointment->ends_at->copy()
            : $startAt->copy()->addMinutes(30);

        try {
            $startAt = $startAt->setTimezone($timezone);
            $endAt = $endAt->setTimezone($timezone);
        } catch (\Throwable) {
            $timezone = (string) config('app.timezone', 'UTC');
            $startAt = $startAt->setTimezone($timezone);
            $endAt = $endAt->setTimezone($timezone);
        }

        if ($endAt->lessThanOrEqualTo($startAt)) {
            $endAt = $startAt->copy()->addMinutes(30);
        }

        return [$startAt, $endAt, $timezone];
    }

    private function appointmentTitle(Appointment $appointment, Lead $lead): string
    {
        $title = trim((string) ($appointment->title ?? ''));

        if ($title !== '') {
            return $title;
        }

        $name = trim((string) implode(' ', array_filter([$lead->first_name, $lead->last_name])));

        if ($name !== '') {
            return 'Meeting with '.$name;
        }

        return 'Booked meeting';
    }

    private function appointmentDescription(Appointment $appointment, Lead $lead): string
    {
        $description = trim((string) ($appointment->description ?? ''));

        if ($description !== '') {
            return $description;
        }

        $leadReference = trim((string) implode(' ', array_filter([$lead->first_name, $lead->last_name])));

        if ($leadReference === '' && is_string($lead->email) && $lead->email !== '') {
            $leadReference = $lead->email;
        }

        return $leadReference !== ''
            ? 'Appointment booked for '.$leadReference.'.'
            : 'Appointment booked from Marketion.';
    }

    /**
     * @return list<array{email: string, displayName?: string}>
     */
    private function attendeesForLead(Lead $lead): array
    {
        $attendees = [];
        $email = trim((string) ($lead->email ?? ''));

        if ($email !== '') {
            $name = trim((string) implode(' ', array_filter([$lead->first_name, $lead->last_name])));
            $entry = ['email' => $email];

            if ($name !== '') {
                $entry['displayName'] = $name;
            }

            $attendees[] = $entry;
        }

        return $attendees;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function attendeesForMicrosoft(Lead $lead): array
    {
        $attendees = [];
        $email = trim((string) ($lead->email ?? ''));

        if ($email !== '') {
            $name = trim((string) implode(' ', array_filter([$lead->first_name, $lead->last_name])));

            $attendees[] = [
                'type' => 'required',
                'emailAddress' => [
                    'address' => $email,
                    'name' => $name !== '' ? $name : $email,
                ],
            ];
        }

        return $attendees;
    }

    private function canonicalProvider(string $provider): ?string
    {
        $normalized = mb_strtolower(trim($provider));

        return match ($normalized) {
            'google', 'google_calendar', 'google-calendar', 'googleworkspace', 'google_workspace' => 'google',
            'microsoft', 'microsoft_calendar', 'microsoft-calendar', 'outlook', 'office365', 'graph' => 'microsoft',
            default => null,
        };
    }

    private function markConnectionSynced(IntegrationConnection $connection): void
    {
        $connection->forceFill([
            'last_synced_at' => now(),
            'last_error' => null,
        ])->save();
    }

    private function markConnectionError(IntegrationConnection $connection, string $error): void
    {
        $connection->forceFill([
            'last_error' => $error,
        ])->save();
    }
}
