<?php

namespace App\Services;

use App\Models\CountryComplianceRule;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\Unsubscribe;
use Carbon\Carbon;

class ComplianceService
{
    /**
     * Validate whether one outbound message is compliant.
     *
     * @return array{allowed: bool, reason: string|null, from: string|null}
     */
    public function evaluate(Message $message): array
    {
        if ($message->direction !== 'outbound') {
            return ['allowed' => true, 'reason' => null, 'from' => null];
        }

        $tenant = Tenant::query()->whereKey($message->tenant_id)->first();

        if ($tenant === null) {
            return ['allowed' => false, 'reason' => 'Tenant context missing for compliance check.', 'from' => null];
        }

        $lead = $message->lead_id
            ? Lead::query()->withoutTenancy()->whereKey($message->lead_id)->first()
            : null;

        $channel = mb_strtolower($message->channel);

        if (! $this->hasConsent($tenant, $lead, $channel, (string) $message->to)) {
            return ['allowed' => false, 'reason' => 'Lead consent is not available for this channel.', 'from' => null];
        }

        if ($this->isInsideQuietHours($tenant)) {
            return ['allowed' => false, 'reason' => 'Tenant quiet hours window is active.', 'from' => null];
        }

        if ($lead !== null && $this->exceedsFrequencyCap($tenant, $lead, $channel)) {
            return ['allowed' => false, 'reason' => 'Lead reached channel frequency cap.', 'from' => null];
        }

        $countryRule = $this->countryRuleFor($tenant->id, $lead?->country_code, $channel);
        $senderId = null;

        if ($countryRule !== null) {
            if ($this->violatesTemplateConstraint($countryRule, $message)) {
                return ['allowed' => false, 'reason' => 'Country template constraints blocked this message.', 'from' => null];
            }

            if ($this->missingOptOutKeyword($countryRule, $message)) {
                return ['allowed' => false, 'reason' => 'Country rule requires opt-out keyword in message body.', 'from' => null];
            }

            $senderId = $countryRule->sender_id ?: null;
        }

        return ['allowed' => true, 'reason' => null, 'from' => $senderId];
    }

    /**
     * Check explicit channel consent and unsubscribe flags.
     */
    private function hasConsent(Tenant $tenant, ?Lead $lead, string $channel, string $destination): bool
    {
        if ($channel === 'email' && $lead !== null && $lead->email_consent === false) {
            return false;
        }

        $settings = $lead !== null && is_array($lead->settings) ? $lead->settings : [];
        $consent = is_array($settings['consent'] ?? null) ? $settings['consent'] : [];

        if (array_key_exists($channel, $consent) && $consent[$channel] === false) {
            return false;
        }

        if ($destination === '') {
            return false;
        }

        return ! Unsubscribe::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->where('channel', $channel)
            ->where('value', $destination)
            ->exists();
    }

    /**
     * Check if now is within tenant quiet hours.
     */
    private function isInsideQuietHours(Tenant $tenant): bool
    {
        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        $quiet = is_array(data_get($settings, 'compliance.quiet_hours'))
            ? data_get($settings, 'compliance.quiet_hours')
            : [];

        if (($quiet['enabled'] ?? false) === false) {
            return false;
        }

        $start = (string) ($quiet['start'] ?? '22:00');
        $end = (string) ($quiet['end'] ?? '08:00');
        $timezone = (string) ($quiet['timezone'] ?? $tenant->timezone ?? 'UTC');

        try {
            $now = Carbon::now($timezone);
        } catch (\Throwable) {
            $now = now();
        }

        [$startHour, $startMinute] = $this->parseClock($start);
        [$endHour, $endMinute] = $this->parseClock($end);

        $startAt = $now->copy()->setTime($startHour, $startMinute);
        $endAt = $now->copy()->setTime($endHour, $endMinute);

        if ($endAt->lessThanOrEqualTo($startAt)) {
            $endAt->addDay();
            if ($now->lessThan($startAt)) {
                $startAt->subDay();
            }
        }

        return $now->between($startAt, $endAt);
    }

    /**
     * Check channel frequency caps from tenant settings.
     */
    private function exceedsFrequencyCap(Tenant $tenant, Lead $lead, string $channel): bool
    {
        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        $caps = is_array(data_get($settings, 'compliance.frequency_caps'))
            ? data_get($settings, 'compliance.frequency_caps')
            : [];

        $maxPerWeek = (int) ($caps[$channel] ?? 0);

        if ($maxPerWeek <= 0) {
            return false;
        }

        $since = now()->subDays(7);

        $sentCount = Message::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->where('lead_id', $lead->id)
            ->where('channel', $channel)
            ->where('direction', 'outbound')
            ->whereIn('status', ['queued', 'sent', 'delivered', 'opened', 'clicked', 'read'])
            ->where('created_at', '>=', $since)
            ->count();

        return $sentCount >= $maxPerWeek;
    }

    private function countryRuleFor(int $tenantId, ?string $countryCode, string $channel): ?CountryComplianceRule
    {
        if (! is_string($countryCode) || trim($countryCode) === '') {
            return null;
        }

        return CountryComplianceRule::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('country_code', mb_strtoupper(trim($countryCode)))
            ->where('channel', $channel)
            ->where('is_active', true)
            ->first();
    }

    private function violatesTemplateConstraint(CountryComplianceRule $rule, Message $message): bool
    {
        $constraints = is_array($rule->template_constraints) ? $rule->template_constraints : [];

        if (($constraints['template_only'] ?? false) === true) {
            return $message->template_id === null;
        }

        return false;
    }

    private function missingOptOutKeyword(CountryComplianceRule $rule, Message $message): bool
    {
        $keywords = is_array($rule->opt_out_keywords) ? $rule->opt_out_keywords : [];

        if ($keywords === []) {
            return false;
        }

        if (! in_array($message->channel, ['sms', 'whatsapp'], true)) {
            return false;
        }

        $body = mb_strtolower((string) $message->body);

        foreach ($keywords as $keyword) {
            $keyword = mb_strtolower(trim((string) $keyword));

            if ($keyword !== '' && str_contains($body, $keyword)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{int, int}
     */
    private function parseClock(string $value): array
    {
        if (! preg_match('/^(\\d{1,2}):(\\d{2})$/', trim($value), $matches)) {
            return [22, 0];
        }

        $hour = max(0, min(23, (int) $matches[1]));
        $minute = max(0, min(59, (int) $matches[2]));

        return [$hour, $minute];
    }
}
