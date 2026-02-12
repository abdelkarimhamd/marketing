<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignStep;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Template;
use App\Models\Unsubscribe;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

        return [
            'subject' => null,
            'body' => null,
            'meta' => [
                'template_name' => $renderingService->renderString((string) $template->whatsapp_template_name, $variables),
                'variables' => $renderingService->renderArray(
                    is_array($template->whatsapp_variables) ? $template->whatsapp_variables : [],
                    $variables
                ),
            ],
        ];
    }

    /**
     * Resolve stop rules from campaign settings.
     *
     * @return array<string, bool>
     */
    public function stopRules(Campaign $campaign): array
    {
        $settings = is_array($campaign->settings) ? $campaign->settings : [];
        $raw = is_array($settings['stop_rules'] ?? null) ? $settings['stop_rules'] : [];

        return [
            'opt_out' => array_key_exists('opt_out', $raw) ? (bool) $raw['opt_out'] : self::DEFAULT_STOP_RULES['opt_out'],
            'won_lost' => array_key_exists('won_lost', $raw) ? (bool) $raw['won_lost'] : self::DEFAULT_STOP_RULES['won_lost'],
            'replied' => array_key_exists('replied', $raw) ? (bool) $raw['replied'] : self::DEFAULT_STOP_RULES['replied'],
        ];
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
}
