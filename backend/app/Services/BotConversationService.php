<?php

namespace App\Services;

use App\Messaging\MessageDispatcher;
use App\Models\Brand;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Tenant;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BotConversationService
{
    public function __construct(
        private readonly BotAutomationService $botAutomationService,
        private readonly LeadEnrichmentService $leadEnrichmentService,
        private readonly MessageDispatcher $messageDispatcher,
        private readonly MessageStatusService $messageStatusService,
        private readonly RealtimeEventService $eventService,
    ) {
    }

    /**
     * Process one website chat message and return bot response payload.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $requestMeta
     * @return array<string, mixed>
     */
    public function processWebsiteChat(
        Tenant $tenant,
        array $payload,
        ?Brand $brand = null,
        array $requestMeta = []
    ): array
    {
        $message = trim((string) ($payload['message'] ?? ''));
        $sessionId = $this->resolveSessionId($payload['session_id'] ?? null);
        $threadKey = $this->websiteThreadKey((int) $tenant->id, $sessionId);
        $lead = $this->resolveWebsiteLead($tenant, $threadKey, $payload, $requestMeta, $brand);

        $inbound = Message::query()->withoutTenancy()->create([
            'tenant_id' => (int) $tenant->id,
            'brand_id' => $brand?->id ?? $lead->brand_id,
            'lead_id' => (int) $lead->id,
            'direction' => 'inbound',
            'status' => 'received',
            'channel' => 'website_chat',
            'thread_key' => $threadKey,
            'to' => 'bot',
            'from' => $sessionId,
            'body' => $message,
            'provider' => 'widget',
            'meta' => [
                'source' => 'website_chat_widget',
                'request' => $requestMeta,
                'session_id' => $sessionId,
                'brand_id' => $brand?->id ?? $lead->brand_id,
            ],
            'sent_at' => now(),
        ]);

        $this->eventService->emit(
            eventName: 'website.chat.message.received',
            tenantId: (int) $tenant->id,
            subjectType: Message::class,
            subjectId: (int) $inbound->id,
            payload: [
                'lead_id' => (int) $lead->id,
                'thread_key' => $threadKey,
            ],
        );

        $bot = $this->botAutomationService->handleInboundMessage(
            tenant: $tenant,
            lead: $lead->refresh(),
            channel: 'website_chat',
            message: $message,
            context: [
                'session_id' => $sessionId,
                'request_handoff' => (bool) ($payload['request_handoff'] ?? false),
            ],
        );

        $replyText = is_string($bot['reply'] ?? null) ? trim((string) $bot['reply']) : '';
        $replyMessage = null;

        if ($replyText !== '') {
            $replyMessage = Message::query()->withoutTenancy()->create([
                'tenant_id' => (int) $tenant->id,
                'brand_id' => $brand?->id ?? $lead->brand_id,
                'lead_id' => (int) $lead->id,
                'direction' => 'outbound',
                'status' => 'sent',
                'channel' => 'website_chat',
                'thread_key' => $threadKey,
                'to' => $sessionId,
                'from' => 'bot',
                'body' => $replyText,
                'provider' => 'bot',
                'meta' => [
                    'source' => 'website_chat_bot',
                    'bot' => [
                        'type' => $bot['type'] ?? null,
                    ],
                    'session_id' => $sessionId,
                    'brand_id' => $brand?->id ?? $lead->brand_id,
                ],
                'sent_at' => now(),
            ]);

            $this->eventService->emit(
                eventName: 'website.chat.bot.replied',
                tenantId: (int) $tenant->id,
                subjectType: Message::class,
                subjectId: (int) $replyMessage->id,
                payload: [
                    'lead_id' => (int) $lead->id,
                    'thread_key' => $threadKey,
                    'type' => $bot['type'] ?? null,
                ],
            );
        }

        return [
            'session_id' => $sessionId,
            'thread_key' => $threadKey,
            'lead' => $lead->refresh(),
            'inbound_message_id' => (int) $inbound->id,
            'bot_reply' => $replyText !== '' ? $replyText : null,
            'bot_reply_message_id' => $replyMessage?->id,
            'brand' => [
                'id' => $brand?->id ?? $lead->brand_id,
                'name' => $brand?->name,
                'slug' => $brand?->slug,
                'landing_domain' => $brand?->landing_domain,
            ],
            'handoff_requested' => (bool) ($bot['handoff_requested'] ?? false),
            'qualified' => (bool) ($bot['qualified'] ?? false),
            'bot' => [
                'type' => $bot['type'] ?? null,
            ],
        ];
    }

    /**
     * Capture inbound WhatsApp messages from webhook payload and run bot flow.
     *
     * @param array<string, mixed> $payload
     */
    public function captureWhatsAppInbound(string $provider, array $payload): int
    {
        $captured = 0;
        $provider = mb_strtolower(trim($provider));
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
                $messages = is_array($value['messages'] ?? null) ? $value['messages'] : [];
                $contacts = is_array($value['contacts'] ?? null) ? $value['contacts'] : [];
                $contactNames = $this->contactNamesByWaId($contacts);
                $phoneNumberId = trim((string) data_get($value, 'metadata.phone_number_id', ''));

                foreach ($messages as $messageRow) {
                    if (! is_array($messageRow)) {
                        continue;
                    }

                    $inboundText = $this->extractWhatsAppMessageBody($messageRow);

                    if ($inboundText === '') {
                        continue;
                    }

                    $from = trim((string) ($messageRow['from'] ?? ''));

                    if ($from === '') {
                        continue;
                    }

                    $tenant = $this->resolveTenantForWhatsAppInbound($messageRow, $value, $phoneNumberId, $from);

                    if (! $tenant instanceof Tenant) {
                        continue;
                    }

                    $brand = $this->resolveBrandForWhatsAppInbound($tenant, $phoneNumberId);
                    $displayName = $contactNames[$from] ?? null;
                    $lead = $this->resolveWhatsAppLead($tenant, $from, $displayName, $brand);
                    $threadKey = $this->whatsappThreadKey((int) $tenant->id, $lead->phone ?? $from);
                    $providerMessageId = trim((string) ($messageRow['id'] ?? ''));

                    $inbound = $this->storeInboundWhatsAppMessage(
                        tenant: $tenant,
                        brand: $brand,
                        lead: $lead,
                        provider: $provider,
                        providerMessageId: $providerMessageId,
                        from: $from,
                        body: $inboundText,
                        threadKey: $threadKey,
                        rawPayload: $messageRow,
                        phoneNumberId: $phoneNumberId,
                    );

                    if (! $inbound instanceof Message) {
                        continue;
                    }

                    $captured++;

                    $this->eventService->emit(
                        eventName: 'whatsapp.inbound.received',
                        tenantId: (int) $tenant->id,
                        subjectType: Message::class,
                        subjectId: (int) $inbound->id,
                        payload: [
                            'lead_id' => (int) $lead->id,
                            'thread_key' => $threadKey,
                        ],
                    );

                    $bot = $this->botAutomationService->handleInboundMessage(
                        tenant: $tenant,
                        lead: $lead->refresh(),
                        channel: 'whatsapp',
                        message: $inboundText,
                        context: [
                            'provider' => $provider,
                            'from' => $from,
                            'phone_number_id' => $phoneNumberId !== '' ? $phoneNumberId : null,
                        ],
                    );

                    $reply = is_string($bot['reply'] ?? null) ? trim((string) $bot['reply']) : '';

                    if ($reply !== '') {
                        $this->sendWhatsAppBotReply(
                            tenant: $tenant,
                            lead: $lead->refresh(),
                            to: (string) ($lead->phone ?? $from),
                            threadKey: $threadKey,
                            text: $reply,
                            provider: $provider,
                            sourceMeta: [
                                'inbound_message_id' => (int) $inbound->id,
                                'type' => $bot['type'] ?? null,
                                'phone_number_id' => $brand?->whatsapp_phone_number_id ?: ($phoneNumberId !== '' ? $phoneNumberId : null),
                            ],
                            brand: $brand,
                        );
                    }
                }
            }
        }

        return $captured;
    }

    /**
     * Resolve/create website chat lead bound to a thread key.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $requestMeta
     */
    private function resolveWebsiteLead(
        Tenant $tenant,
        string $threadKey,
        array $payload,
        array $requestMeta,
        ?Brand $brand = null
    ): Lead {
        $messageQuery = Message::query()
            ->withoutTenancy()
            ->where('tenant_id', (int) $tenant->id)
            ->where('channel', 'website_chat')
            ->where('thread_key', $threadKey)
            ->whereNotNull('lead_id');

        if ($brand instanceof Brand) {
            $messageQuery->where(function ($query) use ($brand): void {
                $query
                    ->where('brand_id', (int) $brand->id)
                    ->orWhereNull('brand_id');
            });
        }

        $existingLeadId = $messageQuery
            ->orderByDesc('id')
            ->value('lead_id');

        $lead = null;

        if (is_numeric($existingLeadId) && (int) $existingLeadId > 0) {
            $lead = Lead::query()
                ->withoutTenancy()
                ->where('tenant_id', (int) $tenant->id)
                ->whereKey((int) $existingLeadId)
                ->first();
        }

        $email = is_string($payload['email'] ?? null) ? trim((string) $payload['email']) : '';
        $phone = is_string($payload['phone'] ?? null) ? $this->normalizePhone((string) $payload['phone']) : '';

        if (! $lead instanceof Lead && $email !== '') {
            $lead = $this->findLeadByContact($tenant, $brand, 'email', $email);
        }

        if (! $lead instanceof Lead && $phone !== '') {
            $lead = $this->findLeadByContact($tenant, $brand, 'phone', $phone);
        }

        $leadData = [
            'first_name' => $payload['first_name'] ?? null,
            'last_name' => $payload['last_name'] ?? null,
            'email' => $email !== '' ? $email : (is_string($lead?->email ?? null) ? $lead->email : null),
            'phone' => $phone !== '' ? $phone : (is_string($lead?->phone ?? null) ? $lead->phone : null),
            'company' => $payload['company'] ?? null,
            'city' => $payload['city'] ?? null,
            'country_code' => $payload['country_code'] ?? null,
            'interest' => $payload['interest'] ?? null,
            'service' => $payload['service'] ?? null,
            'locale' => $payload['locale'] ?? null,
            'source' => 'website_chat_bot',
        ];

        $enrichmentContactProvided = is_string($leadData['email'] ?? null) && trim((string) $leadData['email']) !== ''
            || is_string($leadData['phone'] ?? null) && trim((string) $leadData['phone']) !== '';

        if ($enrichmentContactProvided) {
            try {
                $leadData = $this->leadEnrichmentService->enrich($leadData);
            } catch (ValidationException) {
                // Keep chat flow non-blocking; continue with raw values and collect contact later.
            }
        }

        if (! $lead instanceof Lead) {
            $meta = [
                'intake' => [
                    'channel' => 'website_chat',
                    'source' => 'widget',
                    'received_at' => now()->toIso8601String(),
                    'ip' => $requestMeta['ip'] ?? null,
                    'user_agent' => $requestMeta['user_agent'] ?? null,
                    'origin' => $requestMeta['origin'] ?? null,
                    'referer' => $requestMeta['referer'] ?? null,
                    'brand_id' => $brand?->id,
                    'brand_slug' => $brand?->slug,
                ],
                'bot' => [
                    'webchat_thread_key' => $threadKey,
                ],
            ];

            return Lead::query()->withoutTenancy()->create([
                'tenant_id' => (int) $tenant->id,
                'brand_id' => $brand?->id,
                'first_name' => $leadData['first_name'] ?? null,
                'last_name' => $leadData['last_name'] ?? null,
                'email' => $leadData['email'] ?? null,
                'phone' => $leadData['phone'] ?? null,
                'company' => $leadData['company'] ?? null,
                'city' => $leadData['city'] ?? null,
                'country_code' => $leadData['country_code'] ?? null,
                'interest' => $leadData['interest'] ?? null,
                'service' => $leadData['service'] ?? null,
                'locale' => $leadData['locale'] ?? null,
                'source' => 'website_chat_bot',
                'status' => 'new',
                'meta' => $meta,
            ]);
        }

        $updates = [];

        foreach (['first_name', 'last_name', 'email', 'phone', 'company', 'city', 'country_code', 'interest', 'service', 'locale'] as $field) {
            $incoming = $leadData[$field] ?? null;

            if (! is_string($incoming) || trim($incoming) === '') {
                continue;
            }

            if (! is_string($lead->{$field} ?? null) || trim((string) $lead->{$field}) === '') {
                $updates[$field] = trim((string) $incoming);
            }
        }

        $meta = is_array($lead->meta) ? $lead->meta : [];
        $meta['bot'] = [
            ...(is_array($meta['bot'] ?? null) ? $meta['bot'] : []),
            'webchat_thread_key' => $threadKey,
            'last_webchat_message_at' => now()->toIso8601String(),
        ];
        $updates['meta'] = $meta;

        if ($brand instanceof Brand && $lead->brand_id === null) {
            $updates['brand_id'] = (int) $brand->id;
        }

        if ($updates !== []) {
            $lead->forceFill($updates)->save();
        }

        return $lead->refresh();
    }

    private function findLeadByContact(Tenant $tenant, ?Brand $brand, string $field, string $value): ?Lead
    {
        if (! in_array($field, ['email', 'phone'], true)) {
            return null;
        }

        $query = Lead::query()
            ->withoutTenancy()
            ->where('tenant_id', (int) $tenant->id)
            ->where($field, $value);

        if (! $brand instanceof Brand) {
            return $query->first();
        }

        $exactMatch = (clone $query)
            ->where('brand_id', (int) $brand->id)
            ->first();

        if ($exactMatch instanceof Lead) {
            return $exactMatch;
        }

        return (clone $query)
            ->whereNull('brand_id')
            ->first();
    }

    private function resolveSessionId(mixed $sessionId): string
    {
        if (! is_string($sessionId) || trim($sessionId) === '') {
            return (string) Str::uuid();
        }

        return Str::limit(trim($sessionId), 120, '');
    }

    private function websiteThreadKey(int $tenantId, string $sessionId): string
    {
        return 'webchat:'.$tenantId.':'.$sessionId;
    }

    private function whatsappThreadKey(int $tenantId, string $phone): string
    {
        return 'wa:'.$tenantId.':'.$this->normalizePhone($phone);
    }

    /**
     * @param array<int, array<string, mixed>> $contacts
     * @return array<string, string>
     */
    private function contactNamesByWaId(array $contacts): array
    {
        $names = [];

        foreach ($contacts as $contact) {
            if (! is_array($contact)) {
                continue;
            }

            $waId = trim((string) ($contact['wa_id'] ?? ''));
            $name = trim((string) data_get($contact, 'profile.name', ''));

            if ($waId !== '' && $name !== '') {
                $names[$waId] = $name;
                $names['+'.$waId] = $name;
            }
        }

        return $names;
    }

    /**
     * Resolve tenant for one inbound WhatsApp message payload.
     *
     * @param array<string, mixed> $messageRow
     * @param array<string, mixed> $value
     */
    private function resolveTenantForWhatsAppInbound(
        array $messageRow,
        array $value,
        string $phoneNumberId,
        string $from
    ): ?Tenant {
        $tenantIdCandidates = [
            $messageRow['tenant_id'] ?? null,
            $value['tenant_id'] ?? null,
        ];

        foreach ($tenantIdCandidates as $candidate) {
            if (! is_numeric($candidate) || (int) $candidate <= 0) {
                continue;
            }

            $tenant = Tenant::query()
                ->whereKey((int) $candidate)
                ->where('is_active', true)
                ->first();

            if ($tenant instanceof Tenant) {
                return $tenant;
            }
        }

        if ($phoneNumberId !== '') {
            $brand = Brand::query()
                ->withoutTenancy()
                ->where('is_active', true)
                ->where('whatsapp_phone_number_id', $phoneNumberId)
                ->whereHas('tenant', fn ($query) => $query->where('is_active', true))
                ->with('tenant')
                ->first();

            if ($brand?->tenant instanceof Tenant) {
                return $brand->tenant;
            }

            $tenant = Tenant::query()
                ->where('is_active', true)
                ->get()
                ->first(static function (Tenant $row) use ($phoneNumberId): bool {
                    return trim((string) data_get($row->settings, 'bot.whatsapp.phone_number_id', '')) === $phoneNumberId;
                });

            if ($tenant instanceof Tenant) {
                return $tenant;
            }
        }

        $normalizedFrom = $this->normalizePhone($from);

        if ($normalizedFrom !== '') {
            $tenantId = Message::query()
                ->withoutTenancy()
                ->where('channel', 'whatsapp')
                ->where(function ($query) use ($normalizedFrom, $from): void {
                    $query
                        ->where('to', $normalizedFrom)
                        ->orWhere('to', $from)
                        ->orWhere('from', $normalizedFrom)
                        ->orWhere('from', $from);
                })
                ->orderByDesc('id')
                ->value('tenant_id');

            if (is_numeric($tenantId) && (int) $tenantId > 0) {
                $tenant = Tenant::query()
                    ->whereKey((int) $tenantId)
                    ->where('is_active', true)
                    ->first();

                if ($tenant instanceof Tenant) {
                    return $tenant;
                }
            }
        }

        $singleTenant = Tenant::query()->where('is_active', true)->limit(2)->get();

        if ($singleTenant->count() === 1) {
            return $singleTenant->first();
        }

        return null;
    }

    private function resolveBrandForWhatsAppInbound(Tenant $tenant, string $phoneNumberId): ?Brand
    {
        $normalized = trim($phoneNumberId);

        if ($normalized === '') {
            return null;
        }

        return Brand::query()
            ->withoutTenancy()
            ->where('tenant_id', (int) $tenant->id)
            ->where('is_active', true)
            ->where('whatsapp_phone_number_id', $normalized)
            ->first();
    }

    private function resolveWhatsAppLead(Tenant $tenant, string $from, ?string $displayName, ?Brand $brand = null): Lead
    {
        $phone = $this->normalizePhone($from);

        $leadQuery = Lead::query()
            ->withoutTenancy()
            ->where('tenant_id', (int) $tenant->id)
            ->where('phone', $phone);

        if ($brand instanceof Brand) {
            $lead = (clone $leadQuery)
                ->where('brand_id', (int) $brand->id)
                ->first();

            if (! $lead instanceof Lead) {
                $lead = (clone $leadQuery)
                    ->whereNull('brand_id')
                    ->first();
            }
        } else {
            $lead = $leadQuery->first();
        }

        if (! $lead instanceof Lead) {
            $meta = [
                'intake' => [
                    'channel' => 'whatsapp',
                    'source' => 'webhook',
                    'received_at' => now()->toIso8601String(),
                    'brand_id' => $brand?->id,
                    'brand_slug' => $brand?->slug,
                ],
                'bot' => [
                    'whatsapp' => true,
                ],
            ];

            $names = $this->splitName($displayName);

            return Lead::query()->withoutTenancy()->create([
                'tenant_id' => (int) $tenant->id,
                'brand_id' => $brand?->id,
                'first_name' => $names['first_name'],
                'last_name' => $names['last_name'],
                'phone' => $phone !== '' ? $phone : $from,
                'source' => 'whatsapp_bot',
                'status' => 'new',
                'meta' => $meta,
            ]);
        }

        $updates = [];

        if (
            $displayName !== null
            && trim($displayName) !== ''
            && (! is_string($lead->first_name) || trim((string) $lead->first_name) === '')
        ) {
            $names = $this->splitName($displayName);
            $updates = [
                'first_name' => $names['first_name'],
                'last_name' => $names['last_name'],
            ];
        }

        if ($brand instanceof Brand && $lead->brand_id === null) {
            $updates['brand_id'] = (int) $brand->id;
        }

        if ($updates !== []) {
            $lead->forceFill($updates)->save();
        }

        return $lead->refresh();
    }

    /**
     * Store inbound WhatsApp message if not captured before.
     *
     * @param array<string, mixed> $rawPayload
     */
    private function storeInboundWhatsAppMessage(
        Tenant $tenant,
        ?Brand $brand,
        Lead $lead,
        string $provider,
        string $providerMessageId,
        string $from,
        string $body,
        string $threadKey,
        array $rawPayload,
        string $phoneNumberId
    ): ?Message {
        if ($providerMessageId !== '') {
            $message = Message::query()->withoutTenancy()->firstOrCreate(
                [
                    'tenant_id' => (int) $tenant->id,
                    'provider' => $provider,
                    'provider_message_id' => $providerMessageId,
                ],
                [
                    'brand_id' => $brand?->id ?? $lead->brand_id,
                    'lead_id' => (int) $lead->id,
                    'direction' => 'inbound',
                    'status' => 'received',
                    'channel' => 'whatsapp',
                    'thread_key' => $threadKey,
                    'to' => (string) ($lead->phone ?? $from),
                    'from' => $this->normalizePhone($from),
                    'body' => $body,
                    'meta' => [
                        'source' => 'whatsapp_webhook',
                        'raw' => $rawPayload,
                        'brand_id' => $brand?->id ?? $lead->brand_id,
                        'phone_number_id' => $phoneNumberId !== '' ? $phoneNumberId : null,
                    ],
                    'sent_at' => now(),
                ],
            );

            return $message->wasRecentlyCreated ? $message : null;
        }

        return Message::query()->withoutTenancy()->create([
            'tenant_id' => (int) $tenant->id,
            'brand_id' => $brand?->id ?? $lead->brand_id,
            'lead_id' => (int) $lead->id,
            'direction' => 'inbound',
            'status' => 'received',
            'channel' => 'whatsapp',
            'thread_key' => $threadKey,
            'to' => (string) ($lead->phone ?? $from),
            'from' => $this->normalizePhone($from),
            'body' => $body,
            'provider' => $provider,
            'meta' => [
                'source' => 'whatsapp_webhook',
                'raw' => $rawPayload,
                'brand_id' => $brand?->id ?? $lead->brand_id,
                'phone_number_id' => $phoneNumberId !== '' ? $phoneNumberId : null,
            ],
            'sent_at' => now(),
        ]);
    }

    /**
     * Send one outbound WhatsApp bot reply through configured provider.
     *
     * @param array<string, mixed> $sourceMeta
     */
    private function sendWhatsAppBotReply(
        Tenant $tenant,
        Lead $lead,
        string $to,
        string $threadKey,
        string $text,
        string $provider,
        array $sourceMeta = [],
        ?Brand $brand = null
    ): void {
        $phoneNumberId = $brand?->whatsapp_phone_number_id;

        if (! is_string($phoneNumberId) || trim($phoneNumberId) === '') {
            $phoneNumberId = is_string($sourceMeta['phone_number_id'] ?? null)
                ? trim((string) $sourceMeta['phone_number_id'])
                : null;
        }

        $outbound = Message::query()->withoutTenancy()->create([
            'tenant_id' => (int) $tenant->id,
            'brand_id' => $brand?->id ?? $lead->brand_id,
            'lead_id' => (int) $lead->id,
            'direction' => 'outbound',
            'status' => 'queued',
            'channel' => 'whatsapp',
            'thread_key' => $threadKey,
            'to' => $this->normalizePhone($to),
            'from' => $phoneNumberId,
            'body' => $text,
            'provider' => $provider,
            'meta' => [
                'source' => 'whatsapp_bot',
                'bot' => $sourceMeta,
                'brand_id' => $brand?->id ?? $lead->brand_id,
                'phone_number_id' => $phoneNumberId,
            ],
        ]);

        try {
            $result = $this->messageDispatcher->dispatch($outbound->refresh());
            $status = $result->accepted ? $result->status : 'failed';

            $updated = $this->messageStatusService->markDispatched(
                message: $outbound,
                provider: $result->provider,
                providerMessageId: $result->providerMessageId,
                status: $status,
                errorMessage: $result->errorMessage,
                meta: is_array($result->meta) ? $result->meta : [],
            );

            $this->eventService->emit(
                eventName: 'bot.reply.sent',
                tenantId: (int) $tenant->id,
                subjectType: Message::class,
                subjectId: (int) $updated->id,
                payload: [
                    'lead_id' => (int) $lead->id,
                    'channel' => 'whatsapp',
                ],
            );
        } catch (\Throwable $exception) {
            $this->messageStatusService->markDispatched(
                message: $outbound,
                provider: $provider !== '' ? $provider : 'system',
                providerMessageId: null,
                status: 'failed',
                errorMessage: $exception->getMessage(),
            );
        }
    }

    /**
     * Extract textual body from mixed WhatsApp message types.
     *
     * @param array<string, mixed> $messageRow
     */
    private function extractWhatsAppMessageBody(array $messageRow): string
    {
        $body = trim((string) data_get($messageRow, 'text.body', ''));

        if ($body !== '') {
            return $body;
        }

        $button = trim((string) data_get($messageRow, 'button.text', ''));

        if ($button !== '') {
            return $button;
        }

        $interactiveButton = trim((string) data_get($messageRow, 'interactive.button_reply.title', ''));

        if ($interactiveButton !== '') {
            return $interactiveButton;
        }

        $interactiveList = trim((string) data_get($messageRow, 'interactive.list_reply.title', ''));

        if ($interactiveList !== '') {
            return $interactiveList;
        }

        return trim((string) ($messageRow['caption'] ?? ''));
    }

    /**
     * @return array{first_name: ?string, last_name: ?string}
     */
    private function splitName(?string $name): array
    {
        if (! is_string($name) || trim($name) === '') {
            return [
                'first_name' => null,
                'last_name' => null,
            ];
        }

        $parts = preg_split('/\s+/', trim($name), 2);

        return [
            'first_name' => trim((string) ($parts[0] ?? '')) ?: null,
            'last_name' => trim((string) ($parts[1] ?? '')) ?: null,
        ];
    }

    private function normalizePhone(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return '';
        }

        $sanitized = preg_replace('/[^\d\+]/', '', $trimmed);

        if (! is_string($sanitized) || $sanitized === '') {
            return '';
        }

        if (! str_starts_with($sanitized, '+')) {
            $sanitized = '+'.ltrim($sanitized, '+');
        }

        return Str::limit($sanitized, 32, '');
    }
}
