<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebhookInbox;
use App\Services\BotConversationService;
use App\Services\InboundEmailService;
use App\Services\MessageStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WebhookController extends Controller
{
    /**
     * Handle incoming email provider webhook events.
     */
    public function email(
        Request $request,
        string $provider,
        MessageStatusService $statusService,
        InboundEmailService $inboundEmailService
    ): JsonResponse
    {
        $provider = mb_strtolower(trim($provider));
        $capturedInbound = $this->captureInboundEmailReplies($provider, $request, $inboundEmailService);

        return $this->processWebhook(
            request: $request,
            provider: $provider,
            parser: fn (array $payload): array => $this->parseEmailEvents($payload),
            statusService: $statusService,
            capturedInbound: $capturedInbound,
        );
    }

    /**
     * Handle incoming SMS provider webhook events.
     */
    public function sms(Request $request, string $provider, MessageStatusService $statusService): JsonResponse
    {
        $provider = mb_strtolower(trim($provider));

        return $this->processWebhook(
            request: $request,
            provider: $provider,
            parser: fn (array $payload): array => $this->parseSmsEvents($provider, $payload),
            statusService: $statusService,
        );
    }

    /**
     * Handle incoming WhatsApp provider webhook events.
     */
    public function whatsapp(
        Request $request,
        string $provider,
        MessageStatusService $statusService,
        BotConversationService $botConversationService
    ): Response|JsonResponse
    {
        $provider = mb_strtolower(trim($provider));

        if ($request->isMethod('get')) {
            return $this->verifyWhatsAppWebhook($provider, $request);
        }

        $capturedInbound = $this->captureInboundWhatsAppMessages(
            provider: $provider,
            request: $request,
            botConversationService: $botConversationService
        );

        return $this->processWebhook(
            request: $request,
            provider: $provider,
            parser: fn (array $payload): array => $this->parseWhatsAppEvents($provider, $payload),
            statusService: $statusService,
            capturedInbound: $capturedInbound,
        );
    }

    /**
     * Common webhook intake, persistence and message status processing flow.
     *
     * @param callable(array<string, mixed>): array<int, array<string, mixed>> $parser
     */
    private function processWebhook(
        Request $request,
        string $provider,
        callable $parser,
        MessageStatusService $statusService,
        int $capturedInbound = 0
    ): JsonResponse {
        $payload = $request->all();
        $headers = $this->normalizeHeaders($request->headers->all());
        $payloadRaw = $request->getContent();

        if (trim($payloadRaw) === '' && $payload !== []) {
            $payloadRaw = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $inbox = WebhookInbox::query()->withoutTenancy()->create([
            'tenant_id' => null,
            'provider' => $provider,
            'event' => (string) ($payload['event'] ?? $payload['type'] ?? 'webhook'),
            'external_id' => (string) ($payload['id'] ?? $payload['message_id'] ?? ''),
            'signature' => $this->extractSignature($request),
            'headers' => $headers,
            'payload' => $payloadRaw !== '' ? $payloadRaw : '{}',
            'status' => 'pending',
            'attempts' => 0,
            'received_at' => now(),
        ]);

        $processed = 0;
        $resolvedTenantIds = [];

        try {
            $events = $parser(is_array($payload) ? $payload : []);

            foreach ($events as $event) {
                $providerMessageId = trim((string) ($event['provider_message_id'] ?? ''));
                $status = trim((string) ($event['status'] ?? ''));

                if ($providerMessageId === '' || $status === '') {
                    continue;
                }

                $updated = $statusService->applyProviderStatus(
                    provider: $provider,
                    providerMessageId: $providerMessageId,
                    incomingStatus: $status,
                    tenantId: isset($event['tenant_id']) && is_numeric($event['tenant_id'])
                        ? (int) $event['tenant_id']
                        : null,
                    occurredAt: $statusService->parseOccurredAt($event['occurred_at'] ?? null),
                    errorMessage: isset($event['error']) ? (string) $event['error'] : null,
                    meta: is_array($event['meta'] ?? null) ? $event['meta'] : [],
                );

                if ($updated !== null) {
                    $processed++;
                    $resolvedTenantIds[] = (int) $updated->tenant_id;
                }
            }

            $resolvedTenantIds = array_values(array_unique(array_filter(
                $resolvedTenantIds,
                static fn (int $tenantId): bool => $tenantId > 0
            )));

            $inboxTenantId = count($resolvedTenantIds) === 1 ? $resolvedTenantIds[0] : null;

            $inbox->forceFill([
                'tenant_id' => $inboxTenantId,
                'status' => $processed > 0 ? 'processed' : 'ignored',
                'processed_at' => now(),
                'attempts' => $inbox->attempts + 1,
            ])->save();

            return response()->json([
                'message' => 'Webhook accepted.',
                'provider' => $provider,
                'processed' => $processed,
                'inbound_captured' => $capturedInbound,
                'inbox_id' => $inbox->id,
            ]);
        } catch (\Throwable $exception) {
            $inbox->forceFill([
                'status' => 'failed',
                'processed_at' => now(),
                'attempts' => $inbox->attempts + 1,
                'error_message' => $exception->getMessage(),
            ])->save();

            return response()->json([
                'message' => 'Webhook processing failed.',
                'provider' => $provider,
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    /**
     * Parse generic email webhook payload(s).
     *
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function parseEmailEvents(array $payload): array
    {
        $events = [];
        $rows = isset($payload['events']) && is_array($payload['events'])
            ? $payload['events']
            : [$payload];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $events[] = [
                'provider_message_id' => $row['provider_message_id']
                    ?? $row['message_id']
                    ?? $row['MessageSid']
                    ?? $row['id']
                    ?? null,
                'status' => $row['status'] ?? $row['event'] ?? null,
                'tenant_id' => $row['tenant_id'] ?? null,
                'occurred_at' => $row['occurred_at'] ?? $row['timestamp'] ?? null,
                'error' => $row['error'] ?? $row['error_message'] ?? null,
                'meta' => $row,
            ];
        }

        return $events;
    }

    /**
     * Capture inbound email replies and attach them to conversation timeline.
     */
    private function captureInboundEmailReplies(
        string $provider,
        Request $request,
        InboundEmailService $inboundEmailService
    ): int {
        $payload = $request->all();
        $rows = isset($payload['events']) && is_array($payload['events'])
            ? $payload['events']
            : [$payload];

        $captured = 0;

        foreach ($rows as $row) {
            if (! is_array($row) || ! $this->looksLikeInboundEmail($row)) {
                continue;
            }

            $message = $inboundEmailService->captureInboundReply($provider, $row);

            if ($message !== null) {
                $captured++;
            }
        }

        return $captured;
    }

    /**
     * Heuristic to identify inbound reply payloads from email providers.
     *
     * @param array<string, mixed> $row
     */
    private function looksLikeInboundEmail(array $row): bool
    {
        $event = mb_strtolower((string) ($row['event'] ?? $row['type'] ?? ''));

        if (in_array($event, ['inbound', 'reply', 'message.inbound', 'email.inbound'], true)) {
            return true;
        }

        $hasSender = is_string($row['from'] ?? null) && trim((string) $row['from']) !== '';
        $hasRecipient = is_string($row['to'] ?? null) && trim((string) $row['to']) !== '';
        $hasBody = is_string($row['text'] ?? $row['body'] ?? null);
        $hasReference = ! empty($row['in_reply_to']) || ! empty($row['message_id']);

        return $hasSender && $hasRecipient && $hasBody && $hasReference;
    }

    /**
     * Parse SMS provider payload(s).
     *
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function parseSmsEvents(string $provider, array $payload): array
    {
        if ($provider === 'twilio') {
            $error = null;

            if (! empty($payload['ErrorMessage'])) {
                $error = (string) $payload['ErrorMessage'];
            } elseif (! empty($payload['ErrorCode'])) {
                $error = 'Twilio error code: '.(string) $payload['ErrorCode'];
            }

            return [[
                'provider_message_id' => $payload['MessageSid'] ?? $payload['SmsSid'] ?? null,
                'status' => $payload['MessageStatus'] ?? $payload['status'] ?? null,
                'tenant_id' => $payload['tenant_id'] ?? null,
                'occurred_at' => $payload['timestamp'] ?? null,
                'error' => $error,
                'meta' => $payload,
            ]];
        }

        return $this->parseEmailEvents($payload);
    }

    /**
     * Parse WhatsApp provider payload(s).
     *
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function parseWhatsAppEvents(string $provider, array $payload): array
    {
        if (! in_array($provider, ['meta', 'meta_whatsapp'], true)) {
            return $this->parseEmailEvents($payload);
        }

        $events = [];
        $entries = is_array($payload['entry'] ?? null) ? $payload['entry'] : [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $changes = is_array($entry['changes'] ?? null) ? $entry['changes'] : [];

            foreach ($changes as $change) {
                if (! is_array($change)) {
                    continue;
                }

                $value = is_array($change['value'] ?? null) ? $change['value'] : [];
                $statuses = is_array($value['statuses'] ?? null) ? $value['statuses'] : [];

                foreach ($statuses as $statusRow) {
                    if (! is_array($statusRow)) {
                        continue;
                    }

                    $error = null;

                    if (is_array($statusRow['errors'] ?? null) && isset($statusRow['errors'][0])) {
                        $error = (string) (
                            $statusRow['errors'][0]['title']
                            ?? $statusRow['errors'][0]['message']
                            ?? ''
                        );
                    }

                    $events[] = [
                        'provider_message_id' => $statusRow['id'] ?? null,
                        'status' => $statusRow['status'] ?? null,
                        'tenant_id' => $statusRow['tenant_id'] ?? null,
                        'occurred_at' => $statusRow['timestamp'] ?? null,
                        'error' => $error,
                        'meta' => $statusRow,
                    ];
                }
            }
        }

        return $events;
    }

    /**
     * Capture inbound WhatsApp messages and pass through bot automation.
     */
    private function captureInboundWhatsAppMessages(
        string $provider,
        Request $request,
        BotConversationService $botConversationService
    ): int {
        $payload = $request->all();

        if (! is_array($payload) || $payload === []) {
            return 0;
        }

        return $botConversationService->captureWhatsAppInbound($provider, $payload);
    }

    /**
     * Handle Meta WhatsApp webhook verification challenge.
     */
    private function verifyWhatsAppWebhook(string $provider, Request $request): Response
    {
        if (! in_array($provider, ['meta', 'meta_whatsapp'], true)) {
            return response('Unsupported provider verification.', 400);
        }

        $mode = (string) $request->query('hub_mode', $request->query('hub.mode', ''));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));
        $expected = (string) config('messaging.meta_whatsapp.verify_token', '');

        if ($mode !== 'subscribe' || $expected === '' || ! hash_equals($expected, $token)) {
            return response('Forbidden', 403);
        }

        return response($challenge, 200);
    }

    /**
     * Flatten request headers into simple key => value list.
     *
     * @param array<string, array<int, string|null>> $headers
     * @return array<string, string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $values) {
            $normalized[$name] = implode(',', array_filter($values, fn ($value) => $value !== null));
        }

        return $normalized;
    }

    /**
     * Extract provider signature header when available.
     */
    private function extractSignature(Request $request): ?string
    {
        $signature = $request->header('X-Signature')
            ?? $request->header('X-Hub-Signature')
            ?? $request->header('X-Hub-Signature-256')
            ?? $request->header('X-Twilio-Signature');

        if (! is_string($signature) || trim($signature) === '') {
            return null;
        }

        return $signature;
    }
}
