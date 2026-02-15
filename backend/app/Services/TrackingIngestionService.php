<?php

namespace App\Services;

use App\Jobs\ProcessTrackingBatchJob;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TrackingEvent;
use App\Models\TrackingVisitor;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TrackingIngestionService
{
    /**
     * Ensure tenant has a public key and return it.
     */
    public function ensureTenantPublicKey(Tenant $tenant): string
    {
        $current = is_string($tenant->public_key) ? trim($tenant->public_key) : '';

        if ($current !== '') {
            return $current;
        }

        $generated = 'trk_'.Str::lower(Str::random(40));
        $tenant->forceFill(['public_key' => $generated])->save();

        return $generated;
    }

    /**
     * Resolve active tenant by public key.
     */
    public function resolveTenantByPublicKey(string $publicKey): ?Tenant
    {
        $normalized = trim($publicKey);

        if ($normalized === '') {
            return null;
        }

        return Tenant::query()
            ->where('public_key', $normalized)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Build request signature for tracking payload.
     *
     * @param array<string, mixed> $payload
     */
    public function signatureForPayload(array $payload, string $publicKey): string
    {
        return hash_hmac(
            'sha256',
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            $publicKey
        );
    }

    /**
     * Verify payload signature.
     *
     * @param array<string, mixed> $payload
     */
    public function verifySignature(array $payload, ?string $signature, string $publicKey): bool
    {
        if (! is_string($signature) || trim($signature) === '') {
            return false;
        }

        return hash_equals($this->signatureForPayload($payload, $publicKey), trim($signature));
    }

    /**
     * Queue one tracking batch for async ingestion.
     *
     * @param list<array<string, mixed>> $events
     */
    public function queueBatch(Tenant $tenant, array $events, ?string $ip, ?string $userAgent): void
    {
        if ($events === []) {
            return;
        }

        ProcessTrackingBatchJob::dispatch(
            tenantId: (int) $tenant->id,
            events: $events,
            ip: $ip,
            userAgent: $userAgent,
        );
    }

    /**
     * Connect one visitor id to an existing lead via hashed email/phone.
     *
     * @param array<string, mixed> $traits
     */
    public function identifyVisitor(
        Tenant $tenant,
        string $visitorId,
        ?string $email,
        ?string $phone,
        array $traits = []
    ): TrackingVisitor {
        $normalizedVisitorId = trim($visitorId);

        if ($normalizedVisitorId === '') {
            abort(422, 'visitor_id is required.');
        }

        $normalizedEmail = $this->normalizeEmail($email);
        $normalizedPhone = $this->normalizePhone($phone);

        if ($normalizedEmail === null && $normalizedPhone === null) {
            abort(422, 'email or phone is required for identify.');
        }

        $emailHash = $normalizedEmail !== null ? hash('sha256', $normalizedEmail) : null;
        $phoneHash = $normalizedPhone !== null ? hash('sha256', $normalizedPhone) : null;

        $lead = Lead::query()
            ->withoutTenancy()
            ->where('tenant_id', (int) $tenant->id)
            ->where(function ($query) use ($normalizedEmail, $normalizedPhone): void {
                if ($normalizedEmail !== null) {
                    $query->orWhere('email', $normalizedEmail);
                }

                if ($normalizedPhone !== null) {
                    $query->orWhere('phone', $normalizedPhone);
                }
            })
            ->orderByDesc('id')
            ->first();

        /** @var TrackingVisitor $visitor */
        $visitor = TrackingVisitor::query()
            ->withoutTenancy()
            ->firstOrNew([
                'tenant_id' => (int) $tenant->id,
                'visitor_id' => $normalizedVisitorId,
            ]);

        $existingTraits = is_array($visitor->traits_json) ? $visitor->traits_json : [];

        $visitor->forceFill([
            'session_id' => $visitor->session_id,
            'lead_id' => $lead?->id,
            'email_hash' => $emailHash,
            'phone_hash' => $phoneHash,
            'traits_json' => array_merge($existingTraits, Arr::whereNotNull($traits)),
            'last_seen_at' => now(),
            'first_seen_at' => $visitor->exists ? $visitor->first_seen_at : now(),
        ])->save();

        if ($lead !== null) {
            TrackingEvent::query()
                ->withoutTenancy()
                ->where('tenant_id', (int) $tenant->id)
                ->where('visitor_id', $normalizedVisitorId)
                ->whereNull('lead_id')
                ->update(['lead_id' => (int) $lead->id]);
        }

        return $visitor->refresh();
    }

    /**
     * Aggregate simple tracking analytics for admin UI.
     *
     * @return array<string, mixed>
     */
    public function analytics(int $tenantId, CarbonInterface $from, CarbonInterface $to): array
    {
        $eventsQuery = TrackingEvent::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->whereBetween('occurred_at', [$from->toDateTimeString(), $to->toDateTimeString()]);

        $totalEvents = (clone $eventsQuery)->count();
        $uniqueVisitors = (clone $eventsQuery)->distinct('visitor_id')->count('visitor_id');

        $topPages = (clone $eventsQuery)
            ->selectRaw('path, COUNT(*) as hits')
            ->whereNotNull('path')
            ->groupBy('path')
            ->orderByDesc('hits')
            ->limit(15)
            ->get()
            ->map(static fn ($row): array => [
                'path' => (string) $row->path,
                'hits' => (int) $row->hits,
            ])
            ->values()
            ->all();

        $eventBreakdown = (clone $eventsQuery)
            ->selectRaw('event_type, COUNT(*) as total')
            ->groupBy('event_type')
            ->orderByDesc('total')
            ->get()
            ->map(static fn ($row): array => [
                'event_type' => (string) $row->event_type,
                'total' => (int) $row->total,
            ])
            ->values()
            ->all();

        $utmRows = (clone $eventsQuery)
            ->whereNotNull('utm_json')
            ->get(['utm_json']);

        $utmStats = $this->aggregateUtmStats($utmRows);

        $conversions = (clone $eventsQuery)
            ->whereIn('event_type', ['form_submit', 'conversion'])
            ->count();

        return [
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'summary' => [
                'events' => $totalEvents,
                'unique_visitors' => $uniqueVisitors,
                'conversions' => $conversions,
            ],
            'top_pages' => $topPages,
            'event_breakdown' => $eventBreakdown,
            'utm' => $utmStats,
        ];
    }

    /**
     * @param Collection<int, TrackingEvent> $rows
     * @return array<string, list<array<string, mixed>>>
     */
    private function aggregateUtmStats(Collection $rows): array
    {
        $bucket = [
            'utm_source' => [],
            'utm_medium' => [],
            'utm_campaign' => [],
        ];

        foreach ($rows as $row) {
            $utm = is_array($row->utm_json) ? $row->utm_json : [];

            foreach (array_keys($bucket) as $key) {
                $value = trim((string) ($utm[$key] ?? ''));

                if ($value === '') {
                    continue;
                }

                $bucket[$key][$value] = (int) ($bucket[$key][$value] ?? 0) + 1;
            }
        }

        return collect($bucket)
            ->map(static function (array $values): array {
                arsort($values);

                return collect($values)
                    ->take(15)
                    ->map(static fn (int $count, string $value): array => [
                        'value' => $value,
                        'count' => $count,
                    ])
                    ->values()
                    ->all();
            })
            ->all();
    }

    private function normalizeEmail(?string $email): ?string
    {
        if (! is_string($email)) {
            return null;
        }

        $value = mb_strtolower(trim($email));

        return $value !== '' ? $value : null;
    }

    private function normalizePhone(?string $phone): ?string
    {
        if (! is_string($phone)) {
            return null;
        }

        $value = trim($phone);

        if ($value === '') {
            return null;
        }

        $hasPlus = str_starts_with($value, '+');
        $digits = preg_replace('/\D+/', '', $value);

        if (! is_string($digits) || $digits === '') {
            return null;
        }

        return $hasPlus ? '+'.$digits : $digits;
    }
}
