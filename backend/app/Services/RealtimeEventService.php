<?php

namespace App\Services;

use App\Models\IntegrationEventSubscription;
use App\Models\RealtimeEvent;
use Illuminate\Support\Facades\Http;

class RealtimeEventService
{
    /**
     * Persist one realtime event row and push to subscribed outbound webhooks.
     *
     * @param array<string, mixed>|null $payload
     */
    public function emit(
        string $eventName,
        ?int $tenantId,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?array $payload = null
    ): RealtimeEvent {
        $event = RealtimeEvent::query()
            ->withoutTenancy()
            ->create([
                'tenant_id' => $tenantId,
                'event_name' => $eventName,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'payload' => $payload ?? [],
                'occurred_at' => now(),
            ]);

        if ($tenantId !== null && $tenantId > 0) {
            app(DomainEventBusService::class)->emit(
                tenantId: $tenantId,
                eventName: $eventName,
                subjectType: $subjectType,
                subjectId: $subjectId,
                payload: $payload ?? [],
            );

            $this->dispatchSubscribedWebhooks($tenantId, $eventName, [
                'event_id' => $event->id,
                'event' => $eventName,
                'tenant_id' => $tenantId,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'payload' => $payload ?? [],
                'occurred_at' => $event->occurred_at?->toIso8601String(),
            ]);
        }

        return $event;
    }

    /**
     * Send one event payload to all tenant integration subscriptions.
     *
     * @param array<string, mixed> $payload
     */
    private function dispatchSubscribedWebhooks(int $tenantId, string $eventName, array $payload): void
    {
        $subscriptions = IntegrationEventSubscription::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get();

        foreach ($subscriptions as $subscription) {
            $events = is_array($subscription->events) ? $subscription->events : [];

            if ($events !== [] && ! in_array('*', $events, true) && ! in_array($eventName, $events, true)) {
                continue;
            }

            $headers = [
                'Content-Type' => 'application/json',
            ];

            if (is_string($subscription->secret) && $subscription->secret !== '') {
                $headers['X-Event-Signature'] = hash_hmac(
                    'sha256',
                    json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
                    $subscription->secret
                );
            }

            try {
                $response = Http::timeout(8)->withHeaders($headers)->post(
                    $subscription->endpoint_url,
                    $payload
                );

                if ($response->successful()) {
                    $subscription->forceFill([
                        'last_delivered_at' => now(),
                        'last_error' => null,
                    ])->save();

                    continue;
                }

                $subscription->forceFill([
                    'last_error' => sprintf('HTTP %d: %s', $response->status(), $response->body()),
                ])->save();
            } catch (\Throwable $exception) {
                $subscription->forceFill([
                    'last_error' => $exception->getMessage(),
                ])->save();
            }
        }
    }
}
