<?php

namespace App\Services;

use App\Models\ConsentEvent;
use App\Models\Lead;
use App\Models\LeadPreference;
use Illuminate\Support\Str;

class ConsentService
{
    /**
     * Record one proof-of-consent event and sync lead settings.
     *
     * @param array<string, mixed> $context
     */
    public function recordLeadConsent(
        Lead $lead,
        string $channel,
        bool $granted,
        string $source = 'system',
        ?string $proofMethod = null,
        ?string $proofRef = null,
        array $context = [],
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): ConsentEvent {
        $channel = mb_strtolower(trim($channel));

        $event = ConsentEvent::query()->withoutTenancy()->create([
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->id,
            'channel' => $channel,
            'granted' => $granted,
            'source' => $source,
            'proof_method' => $proofMethod,
            'proof_ref' => $proofRef,
            'context' => $context,
            'collected_at' => now(),
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);

        $settings = is_array($lead->settings) ? $lead->settings : [];
        $consent = is_array($settings['consent'] ?? null) ? $settings['consent'] : [];
        $consent[$channel] = $granted;
        $consent[$channel.'_updated_at'] = now()->toIso8601String();
        $settings['consent'] = $consent;

        $lead->forceFill([
            'email_consent' => $channel === 'email' ? $granted : $lead->email_consent,
            'consent_updated_at' => now(),
            'settings' => $settings,
        ])->save();

        $preference = $this->ensureLeadPreference($lead);
        $channels = is_array($preference->channels) ? $preference->channels : [];
        $channels[$channel] = $granted;

        $preference->forceFill([
            'channels' => $channels,
            'last_confirmed_at' => now(),
        ])->save();

        return $event;
    }

    /**
     * Ensure lead preference row exists with durable token.
     */
    public function ensureLeadPreference(Lead $lead): LeadPreference
    {
        return LeadPreference::query()
            ->withoutTenancy()
            ->firstOrCreate(
                [
                    'tenant_id' => $lead->tenant_id,
                    'lead_id' => $lead->id,
                ],
                [
                    'email' => $lead->email,
                    'phone' => $lead->phone,
                    'locale' => $lead->locale,
                    'channels' => [
                        'email' => $lead->email_consent ?? true,
                        'sms' => true,
                        'whatsapp' => true,
                    ],
                    'topics' => [],
                    'token' => $this->generatePreferenceToken(),
                    'last_confirmed_at' => now(),
                ]
            );
    }

    /**
     * Update preference channels/topics from public preference center.
     *
     * @param array<string, bool> $channels
     * @param list<string> $topics
     */
    public function updatePreference(
        LeadPreference $preference,
        array $channels,
        array $topics,
        ?string $locale = null
    ): LeadPreference {
        $existingChannels = is_array($preference->channels) ? $preference->channels : [];
        $mergedChannels = array_merge($existingChannels, $channels);

        $preference->forceFill([
            'channels' => $mergedChannels,
            'topics' => array_values(array_unique(array_filter($topics))),
            'locale' => $locale ?: $preference->locale,
            'last_confirmed_at' => now(),
        ])->save();

        if ($preference->lead !== null) {
            $lead = $preference->lead;
            $settings = is_array($lead->settings) ? $lead->settings : [];
            $settings['consent'] = array_merge(
                is_array($settings['consent'] ?? null) ? $settings['consent'] : [],
                $mergedChannels
            );
            $settings['topics'] = $preference->topics;

            $lead->forceFill([
                'email_consent' => (bool) ($mergedChannels['email'] ?? $lead->email_consent),
                'consent_updated_at' => now(),
                'locale' => $locale ?: $lead->locale,
                'settings' => $settings,
            ])->save();
        }

        return $preference->refresh();
    }

    private function generatePreferenceToken(): string
    {
        return Str::lower(Str::random(48));
    }
}

