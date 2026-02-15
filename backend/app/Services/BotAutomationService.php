<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Lead;
use App\Models\Tenant;
use Illuminate\Support\Str;

class BotAutomationService
{
    public function __construct(
        private readonly LeadAssignmentService $assignmentService,
        private readonly RealtimeEventService $eventService,
    ) {
    }

    /**
     * Resolve normalized bot settings for one tenant.
     *
     * @return array<string, mixed>
     */
    public function settingsForTenant(Tenant $tenant): array
    {
        $defaults = $this->normalizeSettings(
            is_array(config('bot')) ? config('bot') : []
        );
        $rawStored = is_array(data_get($tenant->settings, 'bot'))
            ? data_get($tenant->settings, 'bot')
            : [];
        $stored = $this->normalizeSettings(
            $rawStored
        );

        $merged = array_replace_recursive($defaults, $stored);

        if (array_key_exists('handoff_keywords', $rawStored)) {
            $merged['handoff_keywords'] = $stored['handoff_keywords'] ?? [];
        }

        if (array_key_exists('faq', $rawStored)) {
            $merged['faq'] = $stored['faq'] ?? [];
        }

        if (is_array($rawStored['qualification'] ?? null) && array_key_exists('questions', $rawStored['qualification'])) {
            data_set($merged, 'qualification.questions', data_get($stored, 'qualification.questions', []));
        }

        if (is_array($rawStored['appointment'] ?? null) && array_key_exists('keywords', $rawStored['appointment'])) {
            data_set($merged, 'appointment.keywords', data_get($stored, 'appointment.keywords', []));
        }

        return $this->normalizeSettings($merged);
    }

    /**
     * Process one inbound message through bot automation.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function handleInboundMessage(
        Tenant $tenant,
        Lead $lead,
        string $channel,
        string $message,
        array $context = []
    ): array {
        $settings = $this->settingsForTenant($tenant);
        $normalizedChannel = mb_strtolower(trim($channel));
        $text = trim($message);

        if (! (bool) ($settings['enabled'] ?? false)) {
            return $this->result(reply: null, type: 'disabled', settings: $settings);
        }

        if (! (bool) data_get($settings, 'channels.'.$normalizedChannel, false)) {
            return $this->result(reply: null, type: 'channel_disabled', settings: $settings);
        }

        if (($context['request_handoff'] ?? false) === true) {
            $text = 'agent';
        }

        if ($text === '') {
            return $this->result(
                reply: (string) ($settings['default_reply'] ?? ''),
                type: 'default',
                settings: $settings
            );
        }

        if ($this->containsKeyword($text, (array) ($settings['handoff_keywords'] ?? []))) {
            return $this->requestHandoff($tenant, $lead, $normalizedChannel, $settings, $text, $context);
        }

        $appointmentReply = $this->matchAppointmentReply($settings, $text);

        if ($appointmentReply !== null) {
            $this->recordLeadActivity(
                lead: $lead,
                type: 'bot.appointment.link_shared',
                description: 'Bot shared appointment booking link.',
                properties: [
                    'channel' => $normalizedChannel,
                    'message' => $text,
                ],
            );

            $this->eventService->emit(
                eventName: 'bot.appointment.link_shared',
                tenantId: (int) $tenant->id,
                subjectType: Lead::class,
                subjectId: (int) $lead->id,
                payload: [
                    'channel' => $normalizedChannel,
                ],
            );

            return $this->result(
                reply: $appointmentReply,
                type: 'appointment',
                settings: $settings
            );
        }

        $faqReply = $this->matchFaqReply($settings, $text);

        if ($faqReply !== null) {
            $this->recordLeadActivity(
                lead: $lead,
                type: 'bot.faq.answered',
                description: 'Bot answered using FAQ automation.',
                properties: [
                    'channel' => $normalizedChannel,
                    'message' => $text,
                ],
            );

            $this->eventService->emit(
                eventName: 'bot.faq.answered',
                tenantId: (int) $tenant->id,
                subjectType: Lead::class,
                subjectId: (int) $lead->id,
                payload: [
                    'channel' => $normalizedChannel,
                ],
            );

            return $this->result(
                reply: $faqReply,
                type: 'faq',
                settings: $settings
            );
        }

        if ((bool) data_get($settings, 'qualification.enabled', true)) {
            return $this->processQualification(
                tenant: $tenant,
                lead: $lead,
                channel: $normalizedChannel,
                incomingMessage: $text,
                settings: $settings,
            );
        }

        return $this->result(
            reply: (string) ($settings['default_reply'] ?? ''),
            type: 'default',
            settings: $settings
        );
    }

    /**
     * Process qualification state machine for one lead.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function processQualification(
        Tenant $tenant,
        Lead $lead,
        string $channel,
        string $incomingMessage,
        array $settings
    ): array {
        $questions = collect((array) data_get($settings, 'qualification.questions', []))
            ->filter(static fn (mixed $row): bool => is_array($row) && trim((string) ($row['key'] ?? '')) !== '')
            ->mapWithKeys(static fn (array $row): array => [
                (string) $row['key'] => $row,
            ])
            ->all();

        if ($questions === []) {
            return $this->result(
                reply: (string) ($settings['default_reply'] ?? ''),
                type: 'default',
                settings: $settings
            );
        }

        $meta = is_array($lead->meta) ? $lead->meta : [];
        $qualification = is_array(data_get($meta, 'bot.qualification'))
            ? data_get($meta, 'bot.qualification')
            : [];
        $answers = is_array($qualification['answers'] ?? null) ? $qualification['answers'] : [];
        $pendingKey = is_string($qualification['pending_key'] ?? null)
            ? trim((string) $qualification['pending_key'])
            : '';
        $pendingKey = $pendingKey !== '' && isset($questions[$pendingKey]) ? $pendingKey : '';

        if ($pendingKey !== '') {
            $application = $this->applyAnswerToLead(
                lead: $lead,
                question: $questions[$pendingKey],
                answer: $incomingMessage
            );

            if (! $application['accepted']) {
                $meta['bot'] = [
                    ...(is_array($meta['bot'] ?? null) ? $meta['bot'] : []),
                    'qualification' => [
                        'answers' => $answers,
                        'pending_key' => $pendingKey,
                        'updated_at' => now()->toIso8601String(),
                    ],
                ];
                $lead->forceFill(['meta' => $meta])->save();

                return $this->result(
                    reply: (string) $application['reply'],
                    type: 'qualification_validation',
                    settings: $settings
                );
            }

            $answers[$pendingKey] = $application['answer'];
            $updates = is_array($application['updates'] ?? null) ? $application['updates'] : [];

            if ($updates !== []) {
                $lead->forceFill($updates)->save();
            }

            $pendingKey = '';
        }

        $requiredKeys = array_values(array_map(
            static fn (array $row): string => (string) $row['key'],
            array_filter($questions, static fn (array $row): bool => (bool) ($row['required'] ?? false))
        ));

        if ($requiredKeys === []) {
            $requiredKeys = array_keys($questions);
        }

        $nextRequiredKey = null;

        foreach ($requiredKeys as $key) {
            $value = is_string($answers[$key] ?? null) ? trim((string) $answers[$key]) : '';

            if ($value === '') {
                $nextRequiredKey = $key;
                break;
            }
        }

        if ($nextRequiredKey !== null) {
            $pendingKey = $nextRequiredKey;

            $meta['bot'] = [
                ...(is_array($meta['bot'] ?? null) ? $meta['bot'] : []),
                'qualification' => [
                    'answers' => $answers,
                    'pending_key' => $pendingKey,
                    'updated_at' => now()->toIso8601String(),
                ],
            ];

            $lead->forceFill(['meta' => $meta])->save();

            $questionText = (string) ($questions[$pendingKey]['question'] ?? $settings['default_reply'] ?? '');

            $this->recordLeadActivity(
                lead: $lead,
                type: 'bot.qualification.question_asked',
                description: 'Bot asked next qualification question.',
                properties: [
                    'channel' => $channel,
                    'question_key' => $pendingKey,
                ],
            );

            return $this->result(
                reply: $questionText,
                type: 'qualification_question',
                settings: $settings
            );
        }

        $meta['bot'] = [
            ...(is_array($meta['bot'] ?? null) ? $meta['bot'] : []),
            'qualification' => [
                'answers' => $answers,
                'pending_key' => null,
                'completed_at' => now()->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
            ],
            'qualified_at' => now()->toIso8601String(),
        ];

        $updates = ['meta' => $meta];

        if ((bool) data_get($settings, 'qualification.auto_qualify', true)) {
            $updates['status'] = 'qualified';
        }

        $lead->forceFill($updates)->save();

        if ((bool) data_get($settings, 'qualification.auto_qualify', true)) {
            $this->assignmentService->assignLead($lead->refresh(), 'bot_qualified');
        }

        $this->recordLeadActivity(
            lead: $lead,
            type: 'bot.lead.qualified',
            description: 'Lead qualification completed by bot automation.',
            properties: [
                'channel' => $channel,
                'answers' => $answers,
                'auto_qualify' => (bool) data_get($settings, 'qualification.auto_qualify', true),
            ],
        );

        $this->eventService->emit(
            eventName: 'bot.lead.qualified',
            tenantId: (int) $tenant->id,
            subjectType: Lead::class,
            subjectId: (int) $lead->id,
            payload: [
                'channel' => $channel,
                'answers_count' => count($answers),
                'auto_qualify' => (bool) data_get($settings, 'qualification.auto_qualify', true),
            ],
        );

        return $this->result(
            reply: (string) ($settings['completion_reply'] ?? ''),
            type: 'qualification_completed',
            qualified: true,
            settings: $settings
        );
    }

    /**
     * Apply one qualification answer onto lead fields.
     *
     * @param array<string, mixed> $question
     * @return array{accepted: bool, answer: string, updates: array<string, mixed>, reply: string}
     */
    private function applyAnswerToLead(Lead $lead, array $question, string $answer): array
    {
        $field = mb_strtolower(trim((string) ($question['field'] ?? '')));
        $value = trim($answer);

        if ($field === '') {
            return [
                'accepted' => true,
                'answer' => $value,
                'updates' => [],
                'reply' => '',
            ];
        }

        if ($field === 'email') {
            if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return [
                    'accepted' => false,
                    'answer' => '',
                    'updates' => [],
                    'reply' => 'Please share a valid email address.',
                ];
            }

            return [
                'accepted' => true,
                'answer' => mb_strtolower($value),
                'updates' => ['email' => mb_strtolower($value)],
                'reply' => '',
            ];
        }

        if ($field === 'phone') {
            $normalizedPhone = $this->normalizePhone($value);

            if ($normalizedPhone === '') {
                return [
                    'accepted' => false,
                    'answer' => '',
                    'updates' => [],
                    'reply' => 'Please share a valid phone number.',
                ];
            }

            return [
                'accepted' => true,
                'answer' => $normalizedPhone,
                'updates' => ['phone' => $normalizedPhone],
                'reply' => '',
            ];
        }

        if (in_array($field, ['full_name', 'name'], true)) {
            $segments = preg_split('/\s+/', $value, 2);
            $firstName = trim((string) ($segments[0] ?? ''));
            $lastName = trim((string) ($segments[1] ?? ''));

            if ($firstName === '') {
                return [
                    'accepted' => false,
                    'answer' => '',
                    'updates' => [],
                    'reply' => 'Please share your name so we can continue.',
                ];
            }

            $updates = [
                'first_name' => $firstName,
            ];

            if ($lastName !== '') {
                $updates['last_name'] = $lastName;
            }

            return [
                'accepted' => true,
                'answer' => trim($firstName.' '.$lastName),
                'updates' => $updates,
                'reply' => '',
            ];
        }

        if (in_array($field, [
            'first_name',
            'last_name',
            'company',
            'interest',
            'service',
            'city',
            'country_code',
            'title',
        ], true)) {
            if ($value === '') {
                return [
                    'accepted' => false,
                    'answer' => '',
                    'updates' => [],
                    'reply' => 'Please provide a value to continue.',
                ];
            }

            $normalized = $field === 'country_code'
                ? mb_strtoupper($value)
                : $value;

            return [
                'accepted' => true,
                'answer' => $normalized,
                'updates' => [$field => $normalized],
                'reply' => '',
            ];
        }

        $meta = is_array($lead->meta) ? $lead->meta : [];
        data_set($meta, 'bot.custom_answers.'.$field, $value);

        return [
            'accepted' => true,
            'answer' => $value,
            'updates' => ['meta' => $meta],
            'reply' => '',
        ];
    }

    /**
     * Trigger handoff to human agent and optional reassignment.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function requestHandoff(
        Tenant $tenant,
        Lead $lead,
        string $channel,
        array $settings,
        string $message,
        array $context
    ): array {
        $meta = is_array($lead->meta) ? $lead->meta : [];
        $botMeta = is_array($meta['bot'] ?? null) ? $meta['bot'] : [];

        $botMeta['handoff_requested_at'] = now()->toIso8601String();
        $botMeta['handoff_channel'] = $channel;
        $botMeta['handoff_message'] = $message;
        $botMeta['handoff_context'] = $context;

        $meta['bot'] = $botMeta;
        $lead->forceFill(['meta' => $meta])->save();

        $this->assignmentService->assignLead($lead->refresh(), 'bot_handoff');

        $this->recordLeadActivity(
            lead: $lead,
            type: 'bot.handoff.requested',
            description: 'Bot requested handoff to human agent.',
            properties: [
                'channel' => $channel,
                'message' => $message,
            ],
        );

        $this->eventService->emit(
            eventName: 'bot.handoff.requested',
            tenantId: (int) $tenant->id,
            subjectType: Lead::class,
            subjectId: (int) $lead->id,
            payload: [
                'channel' => $channel,
            ],
        );

        return $this->result(
            reply: (string) ($settings['handoff_reply'] ?? ''),
            type: 'handoff',
            handoffRequested: true,
            settings: $settings
        );
    }

    /**
     * Return matched FAQ answer if any.
     *
     * @param array<string, mixed> $settings
     */
    private function matchFaqReply(array $settings, string $message): ?string
    {
        $faq = is_array($settings['faq'] ?? null) ? $settings['faq'] : [];

        foreach ($faq as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $answer = is_string($entry['answer'] ?? null) ? trim((string) $entry['answer']) : '';

            if ($answer === '') {
                continue;
            }

            $keywords = $this->normalizeKeywordList($entry['keywords'] ?? null);

            if ($keywords !== [] && $this->containsKeyword($message, $keywords)) {
                return $answer;
            }

            $question = is_string($entry['question'] ?? null) ? trim((string) $entry['question']) : '';

            if ($question !== '' && str_contains(mb_strtolower($message), mb_strtolower($question))) {
                return $answer;
            }
        }

        return null;
    }

    /**
     * Return appointment reply when message asks for booking.
     *
     * @param array<string, mixed> $settings
     */
    private function matchAppointmentReply(array $settings, string $message): ?string
    {
        if (! (bool) data_get($settings, 'appointment.enabled', true)) {
            return null;
        }

        $keywords = $this->normalizeKeywordList(data_get($settings, 'appointment.keywords', []));

        if ($keywords === [] || ! $this->containsKeyword($message, $keywords)) {
            return null;
        }

        $template = trim((string) data_get($settings, 'appointment.reply', ''));
        $bookingUrl = trim((string) data_get($settings, 'appointment.booking_url', ''));

        if ($template === '' && $bookingUrl !== '') {
            $template = 'You can book a slot here: {{booking_url}}';
        }

        if ($template === '') {
            return null;
        }

        if ($bookingUrl !== '') {
            return str_replace('{{booking_url}}', $bookingUrl, $template);
        }

        return str_replace(' {{booking_url}}', '', $template);
    }

    /**
     * Normalize bot settings structure.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function normalizeSettings(array $settings): array
    {
        $normalized = [
            'enabled' => (bool) ($settings['enabled'] ?? true),
            'channels' => [
                'whatsapp' => (bool) data_get($settings, 'channels.whatsapp', true),
                'website_chat' => (bool) data_get($settings, 'channels.website_chat', true),
            ],
            'welcome_message' => trim((string) ($settings['welcome_message'] ?? '')),
            'default_reply' => trim((string) ($settings['default_reply'] ?? '')),
            'handoff_reply' => trim((string) ($settings['handoff_reply'] ?? '')),
            'completion_reply' => trim((string) ($settings['completion_reply'] ?? '')),
            'handoff_keywords' => $this->normalizeKeywordList($settings['handoff_keywords'] ?? []),
            'qualification' => [
                'enabled' => (bool) data_get($settings, 'qualification.enabled', true),
                'auto_qualify' => (bool) data_get($settings, 'qualification.auto_qualify', true),
                'questions' => [],
            ],
            'faq' => [],
            'appointment' => [
                'enabled' => (bool) data_get($settings, 'appointment.enabled', true),
                'keywords' => $this->normalizeKeywordList(data_get($settings, 'appointment.keywords', [])),
                'booking_url' => $this->normalizeNullableString(data_get($settings, 'appointment.booking_url')),
                'reply' => trim((string) data_get($settings, 'appointment.reply', '')),
            ],
        ];

        $questions = is_array(data_get($settings, 'qualification.questions'))
            ? data_get($settings, 'qualification.questions')
            : [];

        foreach ($questions as $row) {
            if (! is_array($row)) {
                continue;
            }

            $key = trim((string) ($row['key'] ?? ''));
            $question = trim((string) ($row['question'] ?? ''));
            $field = trim((string) ($row['field'] ?? ''));

            if ($key === '' || $question === '') {
                continue;
            }

            $normalized['qualification']['questions'][] = [
                'key' => $key,
                'question' => $question,
                'field' => $field !== '' ? $field : $key,
                'required' => (bool) ($row['required'] ?? true),
            ];
        }

        $faq = is_array($settings['faq'] ?? null) ? $settings['faq'] : [];

        foreach ($faq as $row) {
            if (! is_array($row)) {
                continue;
            }

            $answer = trim((string) ($row['answer'] ?? ''));

            if ($answer === '') {
                continue;
            }

            $normalized['faq'][] = [
                'question' => trim((string) ($row['question'] ?? '')),
                'keywords' => $this->normalizeKeywordList($row['keywords'] ?? []),
                'answer' => $answer,
            ];
        }

        if ($normalized['default_reply'] === '') {
            $normalized['default_reply'] = 'Thanks for your message. Our team will follow up shortly.';
        }

        if ($normalized['handoff_reply'] === '') {
            $normalized['handoff_reply'] = 'I am connecting you to a human agent now.';
        }

        if ($normalized['completion_reply'] === '') {
            $normalized['completion_reply'] = 'Thanks. Your request is qualified and routed.';
        }

        return $normalized;
    }

    /**
     * Normalize keyword list.
     *
     * @return list<string>
     */
    private function normalizeKeywordList(mixed $value): array
    {
        $items = is_array($value) ? $value : [$value];

        return collect($items)
            ->map(static fn (mixed $item): string => trim(mb_strtolower((string) $item)))
            ->filter(static fn (string $item): bool => $item !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Check if text contains any keyword token.
     *
     * @param list<string> $keywords
     */
    private function containsKeyword(string $text, array $keywords): bool
    {
        $normalizedText = mb_strtolower(trim($text));

        if ($normalizedText === '' || $keywords === []) {
            return false;
        }

        foreach ($keywords as $keyword) {
            if ($keyword !== '' && str_contains($normalizedText, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build normalized result payload.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function result(
        ?string $reply,
        string $type,
        bool $handoffRequested = false,
        bool $qualified = false,
        array $settings = []
    ): array {
        return [
            'handled' => true,
            'type' => $type,
            'reply' => $reply !== null && trim($reply) !== '' ? trim($reply) : null,
            'handoff_requested' => $handoffRequested,
            'qualified' => $qualified,
            'settings' => $settings,
        ];
    }

    /**
     * Store bot activity on lead.
     *
     * @param array<string, mixed> $properties
     */
    private function recordLeadActivity(Lead $lead, string $type, string $description, array $properties = []): void
    {
        Activity::query()->withoutTenancy()->create([
            'tenant_id' => (int) $lead->tenant_id,
            'actor_id' => null,
            'type' => $type,
            'subject_type' => Lead::class,
            'subject_id' => (int) $lead->id,
            'description' => $description,
            'properties' => $properties,
        ]);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
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
