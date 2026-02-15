<?php

namespace App\Jobs;

use App\Models\Activity;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\CampaignStep;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Template;
use App\Services\CampaignEngineService;
use App\Services\SegmentEvaluationService;
use App\Services\TenantEmailConfigurationService;
use App\Services\VariableRenderingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class GenerateCampaignMessagesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly int $campaignId,
        public readonly ?int $campaignStepId = null
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(
        CampaignEngineService $engine,
        SegmentEvaluationService $segmentService,
        VariableRenderingService $renderingService,
        ?TenantEmailConfigurationService $tenantEmailConfigurationService = null
    ): void {
        $tenantEmailConfigurationService ??= app(TenantEmailConfigurationService::class);

        $campaign = Campaign::query()
            ->withoutTenancy()
            ->with(['segment', 'template.brand', 'brand'])
            ->whereKey($this->campaignId)
            ->first();

        if ($campaign === null || $campaign->segment === null) {
            return;
        }

        if (in_array($campaign->status, [Campaign::STATUS_PAUSED, Campaign::STATUS_COMPLETED], true)) {
            return;
        }

        $step = $this->resolveStep($campaign);
        $template = $this->resolveTemplate($campaign, $step);

        if ($template === null) {
            return;
        }

        $brand = $this->resolveBrand($campaign, $template);
        $channel = $step?->channel ?: $template->channel ?: $campaign->channel;
        $stopRules = $engine->stopRules($campaign);
        $journeyType = is_string($stopRules['journey_type'] ?? null)
            ? (string) $stopRules['journey_type']
            : 'default';
        $fromAddress = $this->resolveSenderIdentity(
            campaign: $campaign,
            brand: $brand,
            channel: $channel,
            tenantEmailConfigurationService: $tenantEmailConfigurationService,
        );
        $created = 0;
        $skipped = 0;

        $leadQuery = $segmentService->queryForSegment($campaign->segment)
            ->orderBy('id');

        $leadQuery->chunkById(200, function (Collection $chunk) use (
            $campaign,
            $step,
            $template,
            $channel,
            $fromAddress,
            $brand,
            $engine,
            $renderingService,
            $journeyType,
            &$created,
            &$skipped
        ): void {
            $timestamp = now();
            $rows = [];
            $leadIds = [];

            foreach ($chunk as $lead) {
                if (! $lead instanceof Lead) {
                    continue;
                }

                if ($engine->shouldStopLead($campaign, $lead, $channel)) {
                    $skipped++;
                    continue;
                }

                $to = $this->resolveDestination($lead, $channel);

                if ($to === null) {
                    $skipped++;
                    continue;
                }

                if ($this->messageAlreadyExists($campaign, $lead, $channel, $step)) {
                    $skipped++;
                    continue;
                }

                $rendered = $engine->renderTemplatePayload($template, $lead, $renderingService);
                $body = $this->applyBrandSignature(
                    channel: $channel,
                    body: is_string($rendered['body'] ?? null) ? $rendered['body'] : null,
                    brand: $brand,
                );
                $meta = is_array($rendered['meta'] ?? null) ? $rendered['meta'] : [];

                if ($brand instanceof Brand) {
                    $meta['brand'] = [
                        'id' => (int) $brand->id,
                        'slug' => $brand->slug,
                    ];

                    if ($channel === 'email' && filled($brand->email_from_name)) {
                        $meta['from_name'] = trim((string) $brand->email_from_name);
                    }

                    if ($channel === 'whatsapp' && filled($brand->whatsapp_phone_number_id)) {
                        $meta['phone_number_id'] = $brand->whatsapp_phone_number_id;
                    }
                }

                if ($journeyType !== 'default') {
                    $meta['journey_type'] = $journeyType;
                }

                $metaPayload = null;

                if ($meta !== []) {
                    $encodedMeta = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $metaPayload = is_string($encodedMeta) ? $encodedMeta : null;
                }

                $rows[] = [
                    'tenant_id' => $campaign->tenant_id,
                    'brand_id' => $brand?->id,
                    'campaign_id' => $campaign->id,
                    'campaign_step_id' => $step?->id,
                    'lead_id' => $lead->id,
                    'template_id' => $template->id,
                    'user_id' => $campaign->created_by,
                    'direction' => 'outbound',
                    'status' => 'queued',
                    'channel' => $channel,
                    'to' => $to,
                    'from' => $fromAddress,
                    'subject' => $rendered['subject'] ?? null,
                    'body' => $body,
                    'meta' => $metaPayload,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];

                $leadIds[] = (int) $lead->id;
                $created++;
            }

            if ($rows === []) {
                return;
            }

            Message::query()->withoutTenancy()->insert($rows);

            $messages = Message::query()
                ->withoutTenancy()
                ->where('tenant_id', $campaign->tenant_id)
                ->where('campaign_id', $campaign->id)
                ->where('channel', $channel)
                ->where('direction', 'outbound')
                ->where('status', 'queued')
                ->whereIn('lead_id', $leadIds)
                ->where('created_at', $timestamp)
                ->orderBy('id')
                ->get(['id', 'tenant_id', 'channel']);

            foreach ($messages as $message) {
                SendCampaignMessageJob::dispatch((int) $message->id)
                    ->onQueue($this->queueFor((string) $message->channel));
            }
        });

        $campaign->forceFill([
            'status' => Campaign::STATUS_RUNNING,
        ])->save();

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => $campaign->tenant_id,
            'actor_id' => null,
            'type' => 'campaign.messages.generated',
            'subject_type' => Campaign::class,
            'subject_id' => $campaign->id,
            'description' => 'Campaign messages were generated.',
            'properties' => [
                'campaign_step_id' => $step?->id,
                'brand_id' => $brand?->id,
                'created_messages' => $created,
                'skipped_messages' => $skipped,
                'channel' => $channel,
            ],
        ]);
    }

    /**
     * Resolve current step, if this is a drip step generation.
     */
    private function resolveStep(Campaign $campaign): ?CampaignStep
    {
        if ($this->campaignStepId === null) {
            return null;
        }

        return CampaignStep::query()
            ->withoutTenancy()
            ->with('template.brand')
            ->where('tenant_id', $campaign->tenant_id)
            ->where('campaign_id', $campaign->id)
            ->whereKey($this->campaignStepId)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Resolve template used for this generation pass.
     */
    private function resolveTemplate(Campaign $campaign, ?CampaignStep $step): ?Template
    {
        if ($step?->template !== null) {
            return $step->template;
        }

        if ($campaign->template !== null) {
            return $campaign->template;
        }

        return null;
    }

    private function resolveBrand(Campaign $campaign, Template $template): ?Brand
    {
        if ($campaign->brand instanceof Brand) {
            return $campaign->brand;
        }

        return $template->brand;
    }

    private function resolveSenderIdentity(
        Campaign $campaign,
        ?Brand $brand,
        string $channel,
        TenantEmailConfigurationService $tenantEmailConfigurationService
    ): ?string {
        $channel = mb_strtolower(trim($channel));

        if ($channel === 'email') {
            if (is_string($brand?->email_from_address) && trim((string) $brand->email_from_address) !== '') {
                return trim((string) $brand->email_from_address);
            }

            return $tenantEmailConfigurationService->fromAddressForTenant((int) $campaign->tenant_id);
        }

        if ($channel === 'sms') {
            if (is_string($brand?->sms_sender_id) && trim((string) $brand->sms_sender_id) !== '') {
                return trim((string) $brand->sms_sender_id);
            }

            return null;
        }

        if ($channel === 'whatsapp') {
            if (is_string($brand?->whatsapp_phone_number_id) && trim((string) $brand->whatsapp_phone_number_id) !== '') {
                return trim((string) $brand->whatsapp_phone_number_id);
            }

            return null;
        }

        return 'system';
    }

    private function applyBrandSignature(string $channel, ?string $body, ?Brand $brand): ?string
    {
        if (! $brand instanceof Brand || ! is_string($body) || trim($body) === '') {
            return $body;
        }

        $signatures = is_array($brand->signatures) ? $brand->signatures : [];
        $channel = mb_strtolower(trim($channel));

        if ($channel === 'email') {
            $signature = null;

            if (is_string($signatures['email_html'] ?? null) && trim((string) $signatures['email_html']) !== '') {
                $signature = trim((string) $signatures['email_html']);
            } elseif (is_string($signatures['email_text'] ?? null) && trim((string) $signatures['email_text']) !== '') {
                $signature = nl2br(e(trim((string) $signatures['email_text'])));
            }

            if ($signature === null || str_contains($body, $signature)) {
                return $body;
            }

            return rtrim($body).'<br><br>'.$signature;
        }

        if ($channel === 'sms' || $channel === 'whatsapp') {
            $signature = is_string($signatures[$channel] ?? null)
                ? trim((string) $signatures[$channel])
                : '';

            if ($signature === '' || str_contains($body, $signature)) {
                return $body;
            }

            return rtrim($body)."\n\n".$signature;
        }

        return $body;
    }

    /**
     * Check if campaign/lead message already exists to avoid duplicates.
     */
    private function messageAlreadyExists(Campaign $campaign, Lead $lead, string $channel, ?CampaignStep $step): bool
    {
        $query = Message::query()
            ->withoutTenancy()
            ->where('tenant_id', $campaign->tenant_id)
            ->where('campaign_id', $campaign->id)
            ->where('lead_id', $lead->id)
            ->where('channel', $channel)
            ->where('direction', 'outbound');

        if ($step === null) {
            $query->whereNull('campaign_step_id');
        } else {
            $query->where('campaign_step_id', $step->id);
        }

        return $query->exists();
    }

    /**
     * Resolve recipient destination by channel.
     */
    private function resolveDestination(Lead $lead, string $channel): ?string
    {
        if ($channel === 'email') {
            $value = is_string($lead->email) ? trim($lead->email) : '';

            return $value !== '' ? $value : null;
        }

        $value = is_string($lead->phone) ? trim($lead->phone) : '';

        return $value !== '' ? $value : null;
    }

    /**
     * Resolve queue lane for send jobs.
     * Defaults to "default" unless channel queues are explicitly enabled.
     */
    private function queueFor(string $channel): string
    {
        $fallback = (string) config('messaging.queues.default', 'default');

        if (! (bool) config('messaging.queues.use_channel_queues', false)) {
            return $fallback;
        }

        return match (mb_strtolower(trim($channel))) {
            'email' => (string) config('messaging.queues.email', 'send-email'),
            'sms' => (string) config('messaging.queues.sms', 'send-sms'),
            'whatsapp' => (string) config('messaging.queues.whatsapp', 'send-whatsapp'),
            default => $fallback,
        };
    }
}
