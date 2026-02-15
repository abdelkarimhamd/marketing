<?php

namespace App\Jobs;

use App\Models\AppSecret;
use App\Models\AppWebhook;
use App\Models\AppWebhookDelivery;
use App\Models\DomainEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class DispatchAppWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $eventId;

    public int $webhookId;

    public function __construct(int $eventId, int $webhookId)
    {
        $this->eventId = $eventId;
        $this->webhookId = $webhookId;
        $this->onQueue('marketplace-webhooks');
    }

    public function handle(): void
    {
        $event = DomainEvent::query()->withoutTenancy()->whereKey($this->eventId)->first();
        $webhook = AppWebhook::query()->withoutTenancy()->whereKey($this->webhookId)->with('install')->first();

        if (! $event instanceof DomainEvent || ! $webhook instanceof AppWebhook) {
            return;
        }

        if ((int) $event->tenant_id !== (int) $webhook->tenant_id || ! $webhook->is_active) {
            return;
        }

        $events = is_array($webhook->events_json) ? $webhook->events_json : [];

        if ($events !== [] && ! in_array('*', $events, true) && ! in_array($event->event_name, $events, true)) {
            return;
        }

        $payload = [
            'event_id' => (int) $event->id,
            'event_name' => $event->event_name,
            'tenant_id' => (int) $event->tenant_id,
            'subject_type' => $event->subject_type,
            'subject_id' => $event->subject_id,
            'occurred_at' => optional($event->occurred_at)->toIso8601String(),
            'payload' => is_array($event->payload) ? $event->payload : [],
        ];

        $secret = AppSecret::query()
            ->withoutTenancy()
            ->where('tenant_id', (int) $webhook->tenant_id)
            ->where('app_install_id', (int) $webhook->app_install_id)
            ->whereNull('revoked_at')
            ->latest('id')
            ->first();

        $signature = null;

        if ($secret instanceof AppSecret) {
            try {
                $decrypted = decrypt($secret->secret_encrypted);
                $signature = hash_hmac(
                    'sha256',
                    json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
                    (string) $decrypted
                );
            } catch (\Throwable) {
                $signature = null;
            }
        }

        $headers = [
            'Content-Type' => 'application/json',
            'X-Marketion-Event' => (string) $event->event_name,
            'X-Marketion-Event-Id' => (string) $event->id,
            'X-Marketion-Tenant' => (string) $event->tenant_id,
        ];

        if ($signature !== null) {
            $headers['X-Marketion-Signature'] = $signature;
        }

        $delivery = AppWebhookDelivery::query()
            ->withoutTenancy()
            ->create([
                'tenant_id' => (int) $event->tenant_id,
                'app_webhook_id' => (int) $webhook->id,
                'domain_event_id' => (int) $event->id,
                'attempt_no' => max(1, (int) $this->attempts()),
                'status' => 'sending',
                'request_headers' => $headers,
                'request_payload' => $payload,
            ]);

        try {
            $response = Http::timeout(15)
                ->withHeaders($headers)
                ->post((string) $webhook->endpoint_url, $payload);

            if ($response->successful()) {
                $delivery->forceFill([
                    'status' => 'delivered',
                    'response_code' => $response->status(),
                    'delivered_at' => now(),
                    'error_message' => null,
                ])->save();

                $webhook->forceFill([
                    'last_delivered_at' => now(),
                    'last_error' => null,
                ])->save();

                $event->forceFill(['dispatched_at' => now()])->save();

                return;
            }

            $message = 'HTTP '.$response->status().': '.$response->body();

            $delivery->forceFill([
                'status' => 'failed',
                'response_code' => $response->status(),
                'error_message' => mb_substr($message, 0, 2000),
            ])->save();

            $webhook->forceFill(['last_error' => mb_substr($message, 0, 2000)])->save();

            throw new \RuntimeException($message);
        } catch (\Throwable $exception) {
            $delivery->forceFill([
                'status' => 'failed',
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),
            ])->save();

            $webhook->forceFill(['last_error' => mb_substr($exception->getMessage(), 0, 2000)])->save();

            throw $exception;
        }
    }
}
