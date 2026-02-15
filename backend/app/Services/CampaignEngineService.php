<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Campaign;
use App\Models\CampaignStep;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Template;
use App\Models\Unsubscribe;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CampaignEngineService
{
    /**
     * Default drip step days.
     *
     * @var list<int>
     */
    private const DEFAULT_DRIP_DAYS = [0, 2, 7];

    /**
     * Default stop rules for campaign execution.
     *
     * @var array<string, bool>
     */
    private const DEFAULT_STOP_RULES = [
        'opt_out' => true,
        'won_lost' => true,
        'replied' => true,
    ];

    /**
     * Default fatigue-control behavior.
     *
     * @var array<string, int|bool>
     */
    private const DEFAULT_FATIGUE_RULES = [
        'enabled' => false,
        'threshold_messages' => 6,
        'reengagement_messages' => 1,
        'sunset' => true,
    ];

    private const JOURNEY_DEFAULT = 'default';

    private const JOURNEY_REENGAGEMENT = 'reengagement';

    /**
     * Outbound states that count as actually-attempted campaign touches.
     *
     * @var list<string>
     */
    private const MESSAGE_SEND_STATUSES = ['sent', 'delivered', 'opened', 'clicked', 'read'];

    /**
     * @var list<string>
     */
    private const WHATSAPP_MESSAGE_TYPES = [
        'template',
        'text',
        'image',
        'video',
        'audio',
        'document',
        'catalog',
        'catalog_list',
        'carousel',
    ];

    /**
     * @var list<string>
     */
    private const WHATSAPP_MEDIA_TYPES = ['image', 'video', 'audio', 'document'];

    /**
     * Launch campaign and enqueue execution job.
     */
    public function launchCampaign(Campaign $campaign): void
    {
        $status = $campaign->isScheduled() && $campaign->start_at !== null && $campaign->start_at->isFuture()
            ? Campaign::STATUS_SCHEDULED
            : Campaign::STATUS_RUNNING;

        $campaign->forceFill([
            'status' => $status,
            'launched_at' => now(),
        ])->save();

        \App\Jobs\LaunchCampaignJob::dispatch((int) $campaign->id);
    }

    /**
     * Build or sync drip steps for Day 0/2/7 defaults.
     *
     * @param array<int, array<string, mixed>>|null $stepPayloads
     * @return Collection<int, CampaignStep>
     */
    public function prepareDripSteps(Campaign $campaign, ?array $stepPayloads = null): Collection
    {
        $templateId = $campaign->template_id;
        $channel = $campaign->channel ?: 'email';

        $desired = collect($stepPayloads ?? [])
            ->map(function (array $row, int $index) use ($templateId, $channel): array {
                $day = (int) ($row['day'] ?? ($row['delay_days'] ?? $index));

                return [
                    'name' => (string) ($row['name'] ?? ('Day '.$day.' Step')),
                    'step_order' => (int) ($row['step_order'] ?? ($index + 1)),
                    'delay_minutes' => $day * 1440,
                    'channel' => (string) ($row['channel'] ?? $channel),
                    'template_id' => $row['template_id'] ?? $templateId,
                    'is_active' => array_key_exists('is_active', $row) ? (bool) $row['is_active'] : true,
                    'settings' => is_array($row['settings'] ?? null) ? $row['settings'] : [],
                ];
            });

        if ($desired->isEmpty()) {
            $desired = collect(self::DEFAULT_DRIP_DAYS)
                ->map(function (int $day, int $index) use ($templateId, $channel): array {
                    return [
                        'name' => "Day {$day} Step",
                        'step_order' => $index + 1,
                        'delay_minutes' => $day * 1440,
                        'channel' => $channel,
                        'template_id' => $templateId,
                        'is_active' => true,
                        'settings' => [],
                    ];
                });
        }

        return DB::transaction(function () use ($campaign, $desired): Collection {
            CampaignStep::query()
                ->withoutTenancy()
                ->where('tenant_id', $campaign->tenant_id)
                ->where('campaign_id', $campaign->id)
                ->delete();

            $created = collect();

            foreach ($desired as $row) {
                $created->push(
                    CampaignStep::query()->withoutTenancy()->create([
                        'tenant_id' => $campaign->tenant_id,
                        'campaign_id' => $campaign->id,
                        'template_id' => $row['template_id'],
                        'name' => $row['name'],
                        'step_order' => $row['step_order'],
                        'channel' => $row['channel'],
                        'delay_minutes' => $row['delay_minutes'],
                        'is_active' => $row['is_active'],
                        'settings' => $row['settings'],
                    ])
                );
            }

            return $created->sortBy('step_order')->values();
        });
    }

    /**
     * Determine if campaign stop rules suppress this lead for the channel.
     */
    public function shouldStopLead(Campaign $campaign, Lead $lead, string $channel): bool
    {
        $rules = $this->stopRules($campaign);

        if (($rules['won_lost'] ?? false) && in_array(mb_strtolower((string) $lead->status), ['won', 'lost'], true)) {
            return true;
        }

        if (($rules['replied'] ?? false) && $this->hasLeadReplied($campaign, $lead)) {
            return true;
        }

        if (($rules['opt_out'] ?? false) && $this->isLeadOptedOut((int) $campaign->tenant_id, $lead, $channel)) {
            return true;
        }

        if ($this->shouldSuppressLeadForFatigue($campaign, $lead, $channel, $rules)) {
            return true;
        }

        return false;
    }

    /**
     * Build rendered payload for message creation.
     *
     * @return array<string, mixed>
     */
    public function renderTemplatePayload(
        Template $template,
        Lead $lead,
        VariableRenderingService $renderingService
    ): array {
        $variables = $renderingService->variablesFromLead($lead);
        $channel = $template->channel;

        if ($channel === 'email') {
            return [
                'subject' => $renderingService->renderString((string) $template->subject, $variables),
                'body' => $renderingService->renderString((string) $template->content, $variables),
                'meta' => null,
            ];
        }

        if ($channel === 'sms') {
            $source = $template->body_text ?? $template->content ?? '';

            return [
                'subject' => null,
                'body' => $renderingService->renderString((string) $source, $variables),
                'meta' => null,
            ];
        }

        $whatsapp = $this->renderWhatsAppPayload($template, $variables, $renderingService);

        return [
            'subject' => null,
            'body' => $whatsapp['body'],
            'meta' => $whatsapp['meta'],
        ];
    }

    /**
     * Build WhatsApp payload/body according to template settings.
     *
     * @param array<string, mixed> $variables
     * @return array{body: string|null, meta: array<string, mixed>}
     */
    public function renderWhatsAppPayload(
        Template $template,
        array $variables,
        VariableRenderingService $renderingService
    ): array {
        $settings = $this->resolveWhatsAppSettings($template);
        $messageType = $this->resolveWhatsAppMessageType($settings, $template);

        $meta = [
            'message_type' => $messageType,
        ];

        $language = trim((string) ($settings['language'] ?? config('messaging.meta_whatsapp.default_language', 'en_US')));
        if ($language !== '') {
            $meta['language'] = $renderingService->renderString($language, $variables);
        }

        if ($messageType === 'template') {
            $meta['template_name'] = $renderingService->renderString((string) $template->whatsapp_template_name, $variables);
            $meta['variables'] = $renderingService->renderArray(
                is_array($template->whatsapp_variables) ? $template->whatsapp_variables : [],
                $variables
            );

            $components = is_array($settings['components'] ?? null)
                ? $renderingService->renderArray($settings['components'], $variables)
                : [];

            if ($components !== []) {
                $meta['components'] = $components;
            }

            return [
                'body' => null,
                'meta' => $meta,
            ];
        }

        if ($messageType === 'text') {
            $source = is_string($settings['text'] ?? null) ? (string) $settings['text'] : '';
            $body = $renderingService->renderString($source, $variables);

            return [
                'body' => trim($body) !== '' ? $body : null,
                'meta' => $meta,
            ];
        }

        if (in_array($messageType, self::WHATSAPP_MEDIA_TYPES, true)) {
            $media = is_array($settings['media'] ?? null) ? $settings['media'] : [];
            $renderedMedia = $this->renderWhatsAppMedia(
                media: $media,
                variables: $variables,
                renderingService: $renderingService,
                tenantId: (int) $template->tenant_id,
            );

            if ($renderedMedia !== []) {
                $meta['media'] = $renderedMedia;
            }

            $caption = trim((string) ($renderedMedia['caption'] ?? ''));

            return [
                'body' => $caption !== '' ? $caption : null,
                'meta' => $meta,
            ];
        }

        if ($messageType === 'catalog' || $messageType === 'catalog_list') {
            $catalog = is_array($settings['catalog'] ?? null)
                ? $renderingService->renderArray($settings['catalog'], $variables)
                : [];

            if ($catalog !== []) {
                $meta['catalog'] = $catalog;
            }

            $fallback = is_string($settings['text'] ?? null)
                ? $renderingService->renderString((string) $settings['text'], $variables)
                : null;

            return [
                'body' => is_string($fallback) && trim($fallback) !== '' ? $fallback : null,
                'meta' => $meta,
            ];
        }

        $carousel = is_array($settings['carousel'] ?? null)
            ? $renderingService->renderArray($settings['carousel'], $variables)
            : [];

        if ($carousel !== []) {
            $meta['carousel'] = $carousel;
        }

        $fallbackText = null;
        if (is_string($settings['text'] ?? null)) {
            $fallbackText = $renderingService->renderString((string) $settings['text'], $variables);
        }

        if ((trim((string) $fallbackText) === '') && is_string($carousel['body'] ?? null)) {
            $fallbackText = (string) $carousel['body'];
        }

        return [
            'body' => is_string($fallbackText) && trim($fallbackText) !== '' ? $fallbackText : null,
            'meta' => $meta,
        ];
    }

    /**
     * Resolve stop rules from campaign settings.
     *
     * @return array<string, mixed>
     */
    public function stopRules(Campaign $campaign): array
    {
        $settings = is_array($campaign->settings) ? $campaign->settings : [];
        $raw = is_array($settings['stop_rules'] ?? null) ? $settings['stop_rules'] : [];
        $fatigueRaw = is_array($raw['fatigue'] ?? null) ? $raw['fatigue'] : [];

        $thresholdMessages = is_numeric($raw['fatigue_threshold_messages'] ?? null)
            ? (int) $raw['fatigue_threshold_messages']
            : (is_numeric($fatigueRaw['threshold_messages'] ?? null)
                ? (int) $fatigueRaw['threshold_messages']
                : (int) self::DEFAULT_FATIGUE_RULES['threshold_messages']);
        $reengagementMessages = is_numeric($raw['fatigue_reengagement_messages'] ?? null)
            ? (int) $raw['fatigue_reengagement_messages']
            : (is_numeric($fatigueRaw['reengagement_messages'] ?? null)
                ? (int) $fatigueRaw['reengagement_messages']
                : (int) self::DEFAULT_FATIGUE_RULES['reengagement_messages']);

        return [
            'opt_out' => array_key_exists('opt_out', $raw) ? (bool) $raw['opt_out'] : self::DEFAULT_STOP_RULES['opt_out'],
            'won_lost' => array_key_exists('won_lost', $raw) ? (bool) $raw['won_lost'] : self::DEFAULT_STOP_RULES['won_lost'],
            'replied' => array_key_exists('replied', $raw) ? (bool) $raw['replied'] : self::DEFAULT_STOP_RULES['replied'],
            'fatigue' => [
                'enabled' => array_key_exists('fatigue_enabled', $raw)
                    ? (bool) $raw['fatigue_enabled']
                    : (array_key_exists('enabled', $fatigueRaw)
                        ? (bool) $fatigueRaw['enabled']
                        : (bool) self::DEFAULT_FATIGUE_RULES['enabled']),
                'threshold_messages' => max(1, min(1000, $thresholdMessages)),
                'reengagement_messages' => max(0, min(50, $reengagementMessages)),
                'sunset' => array_key_exists('fatigue_sunset', $raw)
                    ? (bool) $raw['fatigue_sunset']
                    : (array_key_exists('sunset', $fatigueRaw)
                        ? (bool) $fatigueRaw['sunset']
                        : (bool) self::DEFAULT_FATIGUE_RULES['sunset']),
            ],
            'journey_type' => $this->normalizeJourneyType((string) ($settings['journey_type'] ?? self::JOURNEY_DEFAULT)),
        ];
    }

    /**
     * Apply engagement-based fatigue suppression and re-engagement lifecycle.
     *
     * Rules:
     * - If no open/click/read/reply after N outbound messages, suppress send.
     * - Re-engagement campaigns are only allowed for fatigued leads.
     * - Re-engagement sends are capped; after cap and if sunset policy enabled, lead enters sunset state.
     *
     * @param array<string, mixed> $rules
     */
    private function shouldSuppressLeadForFatigue(Campaign $campaign, Lead $lead, string $channel, array $rules): bool
    {
        $fatigue = is_array($rules['fatigue'] ?? null) ? $rules['fatigue'] : [];

        if (! (bool) ($fatigue['enabled'] ?? false)) {
            return false;
        }

        $channel = mb_strtolower(trim($channel));
        $journeyType = $this->normalizeJourneyType((string) ($rules['journey_type'] ?? self::JOURNEY_DEFAULT));
        $state = $this->fatigueChannelState($lead, $channel);
        $suppressedAt = $this->parseTimestamp($state['suppressed_at'] ?? null);
        $summary = $this->fatigueEngagementSummary(
            tenantId: (int) $campaign->tenant_id,
            leadId: (int) $lead->id,
            channel: $channel,
            suppressedAt: $suppressedAt,
        );

        if (($summary['engaged_after_suppression'] ?? false) === true) {
            $state = $this->clearLeadFatigueSuppression(
                lead: $lead,
                channel: $channel,
                engagementAt: $summary['last_engagement_at'] ?? null
            );
        }

        $threshold = max(1, (int) ($fatigue['threshold_messages'] ?? self::DEFAULT_FATIGUE_RULES['threshold_messages']));
        $reengagementLimit = max(0, (int) ($fatigue['reengagement_messages'] ?? self::DEFAULT_FATIGUE_RULES['reengagement_messages']));
        $sunsetEnabled = (bool) ($fatigue['sunset'] ?? self::DEFAULT_FATIGUE_RULES['sunset']);
        $outboundSinceEngagement = (int) ($summary['outbound_since_engagement'] ?? 0);
        $isFatigued = $outboundSinceEngagement >= $threshold;

        if ($journeyType === self::JOURNEY_REENGAGEMENT) {
            if (! $isFatigued) {
                return true;
            }

            $state = $this->markLeadFatigueSuppressed(
                lead: $lead,
                channel: $channel,
                threshold: $threshold,
                reengagementLimit: $reengagementLimit,
                sunsetEnabled: $sunsetEnabled,
                outboundSinceEngagement: $outboundSinceEngagement,
            );

            if (($state['state'] ?? null) === 'sunset') {
                return true;
            }

            if ($reengagementLimit === 0) {
                if ($sunsetEnabled) {
                    $this->markLeadFatigueSunset($lead, $channel, $reengagementLimit);
                }

                return true;
            }

            $alreadySent = (int) ($summary['outbound_since_suppressed'] ?? 0);

            if ($alreadySent >= $reengagementLimit) {
                if ($sunsetEnabled) {
                    $this->markLeadFatigueSunset($lead, $channel, $reengagementLimit);
                }

                return true;
            }

            $this->markLeadReengagementStarted($lead, $channel, $reengagementLimit, $alreadySent + 1);

            return false;
        }

        if (! $isFatigued) {
            if (($state['suppressed'] ?? false) === true && ($state['state'] ?? null) !== 'sunset') {
                $this->clearLeadFatigueSuppression(
                    lead: $lead,
                    channel: $channel,
                    engagementAt: $summary['last_engagement_at'] ?? null
                );
            }

            return false;
        }

        $this->markLeadFatigueSuppressed(
            lead: $lead,
            channel: $channel,
            threshold: $threshold,
            reengagementLimit: $reengagementLimit,
            sunsetEnabled: $sunsetEnabled,
            outboundSinceEngagement: $outboundSinceEngagement,
        );

        return true;
    }

    /**
     * Summarize outbound pressure and engagement markers for one lead/channel.
     *
     * @return array{
     *   last_engagement_at: Carbon|null,
     *   outbound_since_engagement: int,
     *   outbound_since_suppressed: int,
     *   engaged_after_suppression: bool
     * }
     */
    private function fatigueEngagementSummary(
        int $tenantId,
        int $leadId,
        string $channel,
        ?Carbon $suppressedAt = null
    ): array {
        $baseOutbound = Message::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('lead_id', $leadId)
            ->where('channel', $channel)
            ->where('direction', 'outbound')
            ->whereIn('status', self::MESSAGE_SEND_STATUSES);

        $lastInboundReplyAt = Message::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('lead_id', $leadId)
            ->where('channel', $channel)
            ->where('direction', 'inbound')
            ->max('created_at');

        $lastTrackedOutboundAt = Message::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('lead_id', $leadId)
            ->where('channel', $channel)
            ->where('direction', 'outbound')
            ->where(function ($query): void {
                $query
                    ->whereNotNull('opened_at')
                    ->orWhereNotNull('clicked_at')
                    ->orWhereNotNull('read_at');
            })
            ->max('created_at');

        $lastEngagementAt = $this->latestTimestamp($lastInboundReplyAt, $lastTrackedOutboundAt);

        $outboundSinceEngagementQuery = clone $baseOutbound;

        if ($lastEngagementAt instanceof Carbon) {
            $outboundSinceEngagementQuery->where('created_at', '>', $lastEngagementAt);
        }

        $outboundSinceEngagement = (int) $outboundSinceEngagementQuery->count();
        $outboundSinceSuppressed = 0;

        if ($suppressedAt instanceof Carbon) {
            $outboundSinceSuppressed = (int) (clone $baseOutbound)
                ->where('created_at', '>=', $suppressedAt)
                ->count();
        }

        $engagedAfterSuppression = false;
        if ($suppressedAt instanceof Carbon && $lastEngagementAt instanceof Carbon) {
            $engagedAfterSuppression = $lastEngagementAt->greaterThan($suppressedAt);
        }

        return [
            'last_engagement_at' => $lastEngagementAt,
            'outbound_since_engagement' => $outboundSinceEngagement,
            'outbound_since_suppressed' => $outboundSinceSuppressed,
            'engaged_after_suppression' => $engagedAfterSuppression,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fatigueChannelState(Lead $lead, string $channel): array
    {
        $settings = is_array($lead->settings) ? $lead->settings : [];
        $state = data_get($settings, 'engagement_fatigue.channels.'.$channel, []);

        return is_array($state) ? $state : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function markLeadFatigueSuppressed(
        Lead $lead,
        string $channel,
        int $threshold,
        int $reengagementLimit,
        bool $sunsetEnabled,
        int $outboundSinceEngagement
    ): array {
        $current = $this->fatigueChannelState($lead, $channel);

        if (($current['state'] ?? null) === 'sunset') {
            return $current;
        }

        $nowIso = now()->toIso8601String();
        $changes = [
            'suppressed' => true,
            'state' => 'suppressed',
            'suppressed_at' => is_string($current['suppressed_at'] ?? null) && trim((string) $current['suppressed_at']) !== ''
                ? (string) $current['suppressed_at']
                : $nowIso,
            'threshold_messages' => $threshold,
            'reengagement_messages' => $reengagementLimit,
            'sunset_policy' => $sunsetEnabled,
            'last_outbound_since_engagement' => $outboundSinceEngagement,
            'last_evaluated_at' => $nowIso,
        ];

        $shouldLog = ($current['suppressed'] ?? false) !== true || ($current['state'] ?? null) !== 'suppressed';

        return $this->persistLeadFatigueChannelState(
            lead: $lead,
            channel: $channel,
            changes: $changes,
            activityType: $shouldLog ? 'lead.fatigue.suppressed' : null,
            description: $shouldLog ? 'Lead auto-suppressed by campaign fatigue policy.' : null,
            activityProperties: $shouldLog ? [
                'channel' => $channel,
                'threshold_messages' => $threshold,
                'reengagement_messages' => $reengagementLimit,
                'sunset_policy' => $sunsetEnabled,
                'outbound_since_engagement' => $outboundSinceEngagement,
            ] : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function markLeadReengagementStarted(
        Lead $lead,
        string $channel,
        int $reengagementLimit,
        int $plannedAttempt
    ): array {
        $current = $this->fatigueChannelState($lead, $channel);
        $nowIso = now()->toIso8601String();

        $changes = [
            'suppressed' => true,
            'state' => 'reengagement',
            'reengagement_started_at' => is_string($current['reengagement_started_at'] ?? null) && trim((string) $current['reengagement_started_at']) !== ''
                ? (string) $current['reengagement_started_at']
                : $nowIso,
            'reengagement_attempt_limit' => $reengagementLimit,
            'next_attempt_number' => $plannedAttempt,
            'last_evaluated_at' => $nowIso,
        ];

        $shouldLog = ($current['state'] ?? null) !== 'reengagement';

        return $this->persistLeadFatigueChannelState(
            lead: $lead,
            channel: $channel,
            changes: $changes,
            activityType: $shouldLog ? 'lead.fatigue.reengagement.started' : null,
            description: $shouldLog ? 'Lead entered re-engagement journey due to fatigue suppression.' : null,
            activityProperties: $shouldLog ? [
                'channel' => $channel,
                'reengagement_attempt_limit' => $reengagementLimit,
            ] : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function markLeadFatigueSunset(Lead $lead, string $channel, int $reengagementLimit): array
    {
        $current = $this->fatigueChannelState($lead, $channel);

        if (($current['state'] ?? null) === 'sunset') {
            return $current;
        }

        $nowIso = now()->toIso8601String();

        return $this->persistLeadFatigueChannelState(
            lead: $lead,
            channel: $channel,
            changes: [
                'suppressed' => true,
                'state' => 'sunset',
                'sunset_at' => $nowIso,
                'sunset_reason' => 'reengagement_exhausted',
                'reengagement_attempt_limit' => $reengagementLimit,
                'last_evaluated_at' => $nowIso,
            ],
            activityType: 'lead.fatigue.sunset',
            description: 'Lead moved to sunset policy after re-engagement attempts were exhausted.',
            activityProperties: [
                'channel' => $channel,
                'reengagement_attempt_limit' => $reengagementLimit,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function clearLeadFatigueSuppression(Lead $lead, string $channel, mixed $engagementAt = null): array
    {
        $current = $this->fatigueChannelState($lead, $channel);

        if (($current['suppressed'] ?? false) !== true) {
            return $current;
        }

        $engagement = $this->parseTimestamp($engagementAt);
        $recoveredAt = $engagement instanceof Carbon ? $engagement->toIso8601String() : now()->toIso8601String();

        return $this->persistLeadFatigueChannelState(
            lead: $lead,
            channel: $channel,
            changes: [
                'suppressed' => false,
                'state' => 'active',
                'sunset_at' => null,
                'recovered_at' => $recoveredAt,
                'last_evaluated_at' => now()->toIso8601String(),
            ],
            activityType: 'lead.fatigue.recovered',
            description: 'Lead recovered from fatigue suppression after engagement was detected.',
            activityProperties: [
                'channel' => $channel,
                'engagement_at' => $recoveredAt,
            ],
        );
    }

    /**
     * Persist one channel-specific fatigue state block on lead settings.
     *
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $activityProperties
     * @return array<string, mixed>
     */
    private function persistLeadFatigueChannelState(
        Lead $lead,
        string $channel,
        array $changes,
        ?string $activityType = null,
        ?string $description = null,
        array $activityProperties = []
    ): array {
        $settings = is_array($lead->settings) ? $lead->settings : [];
        $path = 'engagement_fatigue.channels.'.$channel;
        $current = data_get($settings, $path, []);
        $current = is_array($current) ? $current : [];
        $next = array_merge($current, $changes);

        if ($current === $next) {
            return $current;
        }

        data_set($settings, $path, $next);
        $lead->forceFill(['settings' => $settings])->save();

        if ($activityType !== null && $description !== null) {
            Activity::query()->withoutTenancy()->create([
                'tenant_id' => (int) $lead->tenant_id,
                'actor_id' => null,
                'type' => $activityType,
                'subject_type' => Lead::class,
                'subject_id' => (int) $lead->id,
                'description' => $description,
                'properties' => $activityProperties,
            ]);
        }

        return $next;
    }

    private function normalizeJourneyType(string $value): string
    {
        $normalized = mb_strtolower(trim($value));

        if ($normalized === self::JOURNEY_REENGAGEMENT) {
            return self::JOURNEY_REENGAGEMENT;
        }

        return self::JOURNEY_DEFAULT;
    }

    private function latestTimestamp(mixed $first, mixed $second): ?Carbon
    {
        $firstAt = $this->parseTimestamp($first);
        $secondAt = $this->parseTimestamp($second);

        if (! $firstAt instanceof Carbon) {
            return $secondAt;
        }

        if (! $secondAt instanceof Carbon) {
            return $firstAt;
        }

        return $firstAt->greaterThan($secondAt) ? $firstAt : $secondAt;
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Check if lead has replied to this campaign.
     */
    private function hasLeadReplied(Campaign $campaign, Lead $lead): bool
    {
        return Message::query()
            ->withoutTenancy()
            ->where('tenant_id', $campaign->tenant_id)
            ->where('campaign_id', $campaign->id)
            ->where('lead_id', $lead->id)
            ->where('direction', 'inbound')
            ->exists();
    }

    /**
     * Check if lead is opted out for the target channel.
     */
    private function isLeadOptedOut(int $tenantId, Lead $lead, string $channel): bool
    {
        $channel = mb_strtolower($channel);

        if ($channel === 'email') {
            if ($lead->email_consent === false) {
                return true;
            }

            if (! is_string($lead->email) || trim($lead->email) === '') {
                return false;
            }

            return Unsubscribe::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->where('channel', 'email')
                ->where('value', $lead->email)
                ->exists();
        }

        if (! is_string($lead->phone) || trim($lead->phone) === '') {
            return false;
        }

        return Unsubscribe::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('channel', $channel)
            ->where('value', $lead->phone)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveWhatsAppSettings(Template $template): array
    {
        $settings = is_array($template->settings) ? $template->settings : [];
        $whatsapp = Arr::get($settings, 'whatsapp', []);

        return is_array($whatsapp) ? $whatsapp : [];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function resolveWhatsAppMessageType(array $settings, Template $template): string
    {
        $messageType = mb_strtolower(trim((string) ($settings['message_type'] ?? '')));

        if ($messageType === '') {
            $messageType = filled($template->whatsapp_template_name) ? 'template' : 'text';
        }

        if (! in_array($messageType, self::WHATSAPP_MESSAGE_TYPES, true)) {
            return 'template';
        }

        return $messageType;
    }

    /**
     * @param array<string, mixed> $media
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    private function renderWhatsAppMedia(
        array $media,
        array $variables,
        VariableRenderingService $renderingService,
        int $tenantId
    ): array {
        $result = [];
        $attachmentId = is_numeric($media['attachment_id'] ?? null) ? (int) $media['attachment_id'] : null;

        if ($attachmentId !== null && $attachmentId > 0) {
            $result['attachment_id'] = $attachmentId;
        }

        $providerMediaId = is_string($media['provider_media_id'] ?? null)
            ? trim((string) $media['provider_media_id'])
            : '';
        if ($providerMediaId !== '') {
            $result['provider_media_id'] = $renderingService->renderString($providerMediaId, $variables);
        }

        $link = is_string($media['link'] ?? null)
            ? trim($renderingService->renderString((string) $media['link'], $variables))
            : '';

        if ($link === '' && $attachmentId !== null && $attachmentId > 0) {
            $link = trim((string) ($this->resolveMediaLinkFromAttachment($tenantId, $attachmentId) ?? ''));
        }

        if ($link !== '') {
            $result['link'] = $link;
        }

        $caption = is_string($media['caption'] ?? null)
            ? $renderingService->renderString((string) $media['caption'], $variables)
            : null;
        if (is_string($caption) && trim($caption) !== '') {
            $result['caption'] = $caption;
        }

        $filename = is_string($media['filename'] ?? null)
            ? $renderingService->renderString((string) $media['filename'], $variables)
            : null;
        if (is_string($filename) && trim($filename) !== '') {
            $result['filename'] = $filename;
        }

        return $result;
    }

    private function resolveMediaLinkFromAttachment(int $tenantId, int $attachmentId): ?string
    {
        $attachment = Attachment::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->whereKey($attachmentId)
            ->where('entity_type', 'media_library')
            ->first();

        if (! $attachment instanceof Attachment) {
            return null;
        }

        $publicUrl = trim((string) data_get($attachment->meta, 'public_url', ''));
        if ($publicUrl !== '') {
            return $publicUrl;
        }

        try {
            return Storage::disk((string) $attachment->storage_disk)->temporaryUrl(
                (string) $attachment->storage_path,
                now()->addMinutes(30)
            );
        } catch (Throwable) {
            return null;
        }
    }
}
