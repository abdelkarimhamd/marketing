<?php

namespace App\Services;

use App\Jobs\DispatchAppWebhookJob;
use App\Models\AppWebhook;
use App\Models\DomainEvent;

class DomainEventBusService
{
    /**
     * Persist one domain event and queue app-webhook delivery.
     *
     * @param array<string, mixed> $payload
     */
    public function emit(
        int $tenantId,
        string $eventName,
        ?string $subjectType = null,
        ?int $subjectId = null,
        array $payload = []
    ): DomainEvent {
        $event = DomainEvent::query()
            ->withoutTenancy()
            ->create([
                'tenant_id' => $tenantId,
                'event_name' => $eventName,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'payload' => $payload,
                'occurred_at' => now(),
            ]);

        $webhooks = AppWebhook::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get();

        foreach ($webhooks as $webhook) {
            DispatchAppWebhookJob::dispatch((int) $event->id, (int) $webhook->id);
        }

        return $event;
    }
}
