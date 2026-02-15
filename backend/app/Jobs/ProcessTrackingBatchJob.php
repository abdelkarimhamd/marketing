<?php

namespace App\Jobs;

use App\Models\TrackingEvent;
use App\Models\TrackingVisitor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class ProcessTrackingBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @var list<array<string, mixed>>
     */
    public array $events;

    public int $tenantId;

    public ?string $ip;

    public ?string $userAgent;

    /**
     * Create a new job instance.
     *
     * @param list<array<string, mixed>> $events
     */
    public function __construct(int $tenantId, array $events, ?string $ip = null, ?string $userAgent = null)
    {
        $this->tenantId = $tenantId;
        $this->events = $events;
        $this->ip = $ip;
        $this->userAgent = $userAgent;
        $this->onQueue('tracking');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $allowedTypes = [
            'pageview',
            'click',
            'form_start',
            'form_submit',
            'conversion',
            'identify',
            'custom',
        ];

        foreach ($this->events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $visitorId = trim((string) ($event['visitor_id'] ?? ''));

            if ($visitorId === '') {
                continue;
            }

            $eventType = trim((string) ($event['type'] ?? ''));
            $eventType = $eventType !== '' ? $eventType : 'custom';

            if (! in_array($eventType, $allowedTypes, true)) {
                $eventType = 'custom';
            }

            $url = $this->normalizeUrl($event['url'] ?? null);
            $path = $this->normalizePath($event['path'] ?? null, $url);
            $referrer = $this->normalizeUrl($event['referrer'] ?? null);
            $sessionId = $this->normalizeToken($event['session_id'] ?? null);
            $occurredAt = $this->parseTimestamp($event['occurred_at'] ?? null) ?? now();

            $utm = is_array($event['utm'] ?? null) ? $event['utm'] : [];
            $props = is_array($event['props'] ?? null) ? $event['props'] : [];

            $leadId = null;

            /** @var TrackingVisitor $visitor */
            $visitor = TrackingVisitor::query()
                ->withoutTenancy()
                ->firstOrNew([
                    'tenant_id' => $this->tenantId,
                    'visitor_id' => $visitorId,
                ]);

            if ($visitor->exists && $visitor->lead_id !== null) {
                $leadId = (int) $visitor->lead_id;
            }

            $visitorTraits = is_array($visitor->traits_json) ? $visitor->traits_json : [];

            $visitor->forceFill([
                'session_id' => $sessionId ?? $visitor->session_id,
                'lead_id' => $leadId,
                'first_url' => $visitor->first_url ?? $url,
                'last_url' => $url ?? $visitor->last_url,
                'referrer' => $referrer ?? $visitor->referrer,
                'utm_json' => $utm !== [] ? $utm : $visitor->utm_json,
                'traits_json' => array_merge($visitorTraits, Arr::whereNotNull($props)),
                'first_ip' => $visitor->first_ip ?? $this->ip,
                'last_ip' => $this->ip,
                'user_agent' => $this->userAgent ?? $visitor->user_agent,
                'first_seen_at' => $visitor->first_seen_at ?? $occurredAt,
                'last_seen_at' => $occurredAt,
            ]);

            $visitor->save();

            TrackingEvent::query()
                ->withoutTenancy()
                ->create([
                    'tenant_id' => $this->tenantId,
                    'visitor_id' => $visitorId,
                    'session_id' => $sessionId,
                    'lead_id' => $leadId,
                    'event_type' => $eventType,
                    'url' => $url,
                    'path' => $path,
                    'referrer' => $referrer,
                    'utm_json' => $utm,
                    'props_json' => $props,
                    'occurred_at' => $occurredAt,
                ]);
        }
    }

    private function normalizeToken(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $token = trim($value);

        if ($token === '') {
            return null;
        }

        return mb_substr($token, 0, 64);
    }

    private function normalizeUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $url = trim($value);

        if ($url === '') {
            return null;
        }

        return mb_substr($url, 0, 2000);
    }

    private function normalizePath(mixed $value, ?string $url): ?string
    {
        if (is_string($value) && trim($value) !== '') {
            return mb_substr(trim($value), 0, 255);
        }

        if (! is_string($url) || $url === '') {
            return null;
        }

        $parsedPath = parse_url($url, PHP_URL_PATH);

        if (! is_string($parsedPath) || trim($parsedPath) === '') {
            return null;
        }

        return mb_substr(trim($parsedPath), 0, 255);
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
