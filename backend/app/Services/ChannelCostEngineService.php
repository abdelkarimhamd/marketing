<?php

namespace App\Services;

use App\Models\Message;
use App\Models\Tenant;
use App\Models\TenantSubscription;

class ChannelCostEngineService
{
    /**
     * Calculate outbound message economics.
     *
     * @return array{
     *   channel: string,
     *   provider: string,
     *   provider_cost_rate: float,
     *   overhead_cost_rate: float,
     *   revenue_rate: float,
     *   overage_revenue: float,
     *   provider_cost: float,
     *   overhead_cost: float,
     *   total_cost: float,
     *   revenue: float,
     *   profit: float,
     *   margin_percent: float,
     *   currency: string,
     *   reason: array<string, mixed>
     * }
     */
    public function calculateMessageEconomics(Message $message): array
    {
        $tenantId = (int) $message->tenant_id;
        $channel = $this->normalizeChannel((string) $message->channel);
        $provider = $this->normalizeProvider((string) ($message->provider ?? 'unknown'));
        $tenant = $this->tenantModel($tenantId);
        $settings = is_array($tenant?->settings) ? $tenant->settings : [];
        $subscription = $this->activeSubscription($tenantId);

        $providerCostRateSource = 'default';
        $providerCostRate = $this->resolveProviderRate(
            settings: $settings,
            channel: $channel,
            provider: $provider,
            source: $providerCostRateSource
        );

        $overheadRateSource = 'default';
        $overheadRate = $this->resolveOverheadRate(
            settings: $settings,
            channel: $channel,
            source: $overheadRateSource
        );

        $revenueRateSource = 'default';
        $revenueRate = $this->resolveRevenueRate(
            settings: $settings,
            subscription: $subscription,
            channel: $channel,
            source: $revenueRateSource
        );

        $overageRevenue = $this->floatValue(data_get($message->meta, 'billing.overage_amount'));
        $providerCost = round($providerCostRate, 4);
        $overheadCost = round($overheadRate, 4);
        $totalCost = round($providerCost + $overheadCost, 4);
        $revenue = round($revenueRate + $overageRevenue, 4);
        $profit = round($revenue - $totalCost, 4);
        $marginPercent = $this->marginPercent($revenue, $profit, $totalCost);
        $currency = $this->resolveCurrency($tenant, $settings, $subscription);

        return [
            'channel' => $channel,
            'provider' => $provider,
            'provider_cost_rate' => $providerCostRate,
            'overhead_cost_rate' => $overheadRate,
            'revenue_rate' => $revenueRate,
            'overage_revenue' => $overageRevenue,
            'provider_cost' => $providerCost,
            'overhead_cost' => $overheadCost,
            'total_cost' => $totalCost,
            'revenue' => $revenue,
            'profit' => $profit,
            'margin_percent' => $marginPercent,
            'currency' => $currency,
            'reason' => [
                'provider_cost_source' => $providerCostRateSource,
                'overhead_cost_source' => $overheadRateSource,
                'revenue_source' => $revenueRateSource,
            ],
        ];
    }

    public function marginAlertThresholdPercent(int $tenantId): float
    {
        $tenant = $this->tenantModel($tenantId);
        $settings = is_array($tenant?->settings) ? $tenant->settings : [];
        $tenantThreshold = $this->floatValue(data_get($settings, 'cost_engine.margin_alert_threshold_percent'));
        $fallback = $this->floatValue(config('cost_engine.margin_alert_threshold_percent', 15));

        if ($tenantThreshold !== null) {
            return max(-100, min(100, $tenantThreshold));
        }

        return max(-100, min(100, $fallback ?? 15.0));
    }

    public function marginAlertMinMessages(int $tenantId): int
    {
        $tenant = $this->tenantModel($tenantId);
        $settings = is_array($tenant?->settings) ? $tenant->settings : [];
        $tenantValue = $this->intValue(data_get($settings, 'cost_engine.margin_alert_min_messages'));
        $fallback = $this->intValue(config('cost_engine.margin_alert_min_messages', 10)) ?? 10;

        return max(1, $tenantValue ?? $fallback);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function resolveProviderRate(
        array $settings,
        string $channel,
        string $provider,
        string &$source
    ): float {
        $tenantValue = $this->floatValue(
            data_get($settings, "cost_engine.provider_costs.{$channel}.{$provider}")
        );

        if ($tenantValue !== null) {
            $source = 'tenant_settings';

            return max(0, $tenantValue);
        }

        $defaultValue = $this->floatValue(
            data_get(config('cost_engine.provider_costs', []), "{$channel}.{$provider}")
        );

        if ($defaultValue !== null) {
            $source = 'default';

            return max(0, $defaultValue);
        }

        $source = 'default_zero';

        return 0.0;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function resolveOverheadRate(array $settings, string $channel, string &$source): float
    {
        $tenantValue = $this->floatValue(
            data_get($settings, "cost_engine.overhead_per_message.{$channel}")
        );

        if ($tenantValue !== null) {
            $source = 'tenant_settings';

            return max(0, $tenantValue);
        }

        $defaultValue = $this->floatValue(
            data_get(config('cost_engine.overhead_per_message', []), $channel)
        );

        if ($defaultValue !== null) {
            $source = 'default';

            return max(0, $defaultValue);
        }

        $source = 'default_zero';

        return 0.0;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function resolveRevenueRate(
        array $settings,
        ?TenantSubscription $subscription,
        string $channel,
        string &$source
    ): float {
        $tenantValue = $this->floatValue(
            data_get($settings, "cost_engine.revenue_per_message.{$channel}")
        );

        if ($tenantValue !== null) {
            $source = 'tenant_settings';

            return max(0, $tenantValue);
        }

        $subscriptionMetadataRate = $this->floatValue(
            data_get($subscription?->metadata, "channel_revenue_per_message.{$channel}")
        );

        if ($subscriptionMetadataRate !== null) {
            $source = 'subscription_metadata';

            return max(0, $subscriptionMetadataRate);
        }

        $planAddonRate = $this->floatValue(
            data_get($subscription?->plan?->addons, "channel_revenue_per_message.{$channel}")
        );

        if ($planAddonRate !== null) {
            $source = 'plan_addon';

            return max(0, $planAddonRate);
        }

        $defaultValue = $this->floatValue(
            data_get(config('cost_engine.revenue_per_message', []), $channel)
        );

        if ($defaultValue !== null) {
            $source = 'default';

            return max(0, $defaultValue);
        }

        $source = 'default_zero';

        return 0.0;
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantModel(int $tenantId): ?Tenant
    {
        return Tenant::query()
            ->whereKey($tenantId)
            ->first(['id', 'settings', 'currency']);
    }

    private function activeSubscription(int $tenantId): ?TenantSubscription
    {
        return TenantSubscription::query()
            ->withoutTenancy()
            ->with(['plan:id,addons', 'tenant:id,currency'])
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['active', 'trialing'])
            ->latest('id')
            ->first();
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function resolveCurrency(?Tenant $tenant, array $settings, ?TenantSubscription $subscription): string
    {
        $currencyFromSettings = is_string(data_get($settings, 'currency'))
            ? trim((string) data_get($settings, 'currency'))
            : '';

        if ($currencyFromSettings !== '') {
            return mb_strtoupper($currencyFromSettings);
        }

        if (is_string($tenant?->currency) && trim((string) $tenant->currency) !== '') {
            return mb_strtoupper(trim((string) $tenant->currency));
        }

        $subscriptionTenant = $subscription?->tenant;

        if (is_string($subscriptionTenant?->currency) && trim((string) $subscriptionTenant->currency) !== '') {
            return mb_strtoupper(trim((string) $subscriptionTenant->currency));
        }

        return 'USD';
    }

    private function normalizeChannel(string $channel): string
    {
        $normalized = mb_strtolower(trim($channel));

        return in_array($normalized, ['email', 'sms', 'whatsapp'], true)
            ? $normalized
            : 'email';
    }

    private function normalizeProvider(string $provider): string
    {
        $normalized = mb_strtolower(trim($provider));

        return $normalized !== '' ? $normalized : 'unknown';
    }

    private function marginPercent(float $revenue, float $profit, float $totalCost): float
    {
        if ($revenue > 0) {
            return round(($profit / $revenue) * 100, 4);
        }

        if ($totalCost > 0) {
            return -100.0;
        }

        return 0.0;
    }

    private function floatValue(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function intValue(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
