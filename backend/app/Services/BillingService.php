<?php

namespace App\Services;

use App\Models\BillingInvoice;
use App\Models\BillingInvoiceItem;
use App\Models\BillingUsageRecord;
use App\Models\Message;
use App\Models\TenantSubscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BillingService
{
    public function __construct(
        private readonly ChannelCostEngineService $channelCostEngineService
    ) {
    }

    /**
     * Check if a tenant can send one more message in the active period.
     *
     * @return array{allowed: bool, reason: string|null, cost_estimate: float, overage: bool}
     */
    public function evaluateMessageAllowance(int $tenantId, string $channel): array
    {
        $subscription = $this->activeSubscription($tenantId);

        if ($subscription === null) {
            return [
                'allowed' => true,
                'reason' => null,
                'cost_estimate' => 0.0,
                'overage' => false,
            ];
        }

        $bundleLimit = $this->messageBundleLimit($subscription);
        $overageRate = $this->overageRate($subscription);

        if ($bundleLimit <= 0) {
            return [
                'allowed' => true,
                'reason' => null,
                'cost_estimate' => 0.0,
                'overage' => false,
            ];
        }

        [$periodStart, $periodEnd] = $this->periodRange($subscription);

        $usedInPeriod = (int) BillingUsageRecord::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->whereBetween('period_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->sum('messages_count');

        if ($usedInPeriod < $bundleLimit) {
            return [
                'allowed' => true,
                'reason' => null,
                'cost_estimate' => 0.0,
                'overage' => false,
            ];
        }

        $hardLimit = (bool) (
            data_get($subscription->metadata, 'hard_limit')
            ?? $subscription->plan?->hard_limit
            ?? false
        );

        if ($hardLimit) {
            return [
                'allowed' => false,
                'reason' => "Message bundle limit ({$bundleLimit}) reached for current billing period.",
                'cost_estimate' => 0.0,
                'overage' => false,
            ];
        }

        return [
            'allowed' => true,
            'reason' => null,
            'cost_estimate' => $overageRate,
            'overage' => true,
        ];
    }

    /**
     * Record one sent message as billable usage.
     */
    public function trackDispatchedMessage(Message $message): void
    {
        DB::transaction(function () use ($message): void {
            $model = Message::query()
                ->withoutTenancy()
                ->whereKey($message->id)
                ->lockForUpdate()
                ->first();

            if (! $model instanceof Message) {
                return;
            }

            if ($model->cost_tracked_at !== null) {
                return;
            }

            $subscription = $this->activeSubscription((int) $model->tenant_id);
            $economics = $this->channelCostEngineService->calculateMessageEconomics($model);
            $existingMeta = is_array($model->meta) ? $model->meta : [];
            $trackedAt = now();

            $model->forceFill([
                'cost_estimate' => $economics['total_cost'],
                'provider_cost' => $economics['provider_cost'],
                'overhead_cost' => $economics['overhead_cost'],
                'revenue_amount' => $economics['revenue'],
                'profit_amount' => $economics['profit'],
                'margin_percent' => $economics['margin_percent'],
                'cost_tracked_at' => $trackedAt,
                'cost_currency' => $economics['currency'],
                'meta' => array_replace_recursive($existingMeta, [
                    'cost_engine' => [
                        'tracked_at' => $trackedAt->toIso8601String(),
                        'provider_cost_rate' => $economics['provider_cost_rate'],
                        'overhead_cost_rate' => $economics['overhead_cost_rate'],
                        'revenue_rate' => $economics['revenue_rate'],
                        'overage_revenue' => $economics['overage_revenue'],
                        'reason' => $economics['reason'],
                    ],
                ]),
            ])->save();

            $periodDate = $model->sent_at?->toDateString() ?? $trackedAt->toDateString();

            $record = BillingUsageRecord::query()
                ->withoutTenancy()
                ->firstOrCreate(
                    [
                        'tenant_id' => $model->tenant_id,
                        'channel' => $model->channel,
                        'period_date' => $periodDate,
                    ],
                    [
                        'tenant_subscription_id' => $subscription?->id,
                        'messages_count' => 0,
                        'cost_total' => 0,
                        'provider_cost_total' => 0,
                        'overhead_cost_total' => 0,
                        'revenue_total' => 0,
                        'profit_total' => 0,
                    ]
                );

            $record->forceFill([
                'tenant_subscription_id' => $subscription?->id,
                'messages_count' => (int) $record->messages_count + 1,
                'cost_total' => round((float) $record->cost_total + (float) $economics['total_cost'], 4),
                'provider_cost_total' => round((float) $record->provider_cost_total + (float) $economics['provider_cost'], 4),
                'overhead_cost_total' => round((float) $record->overhead_cost_total + (float) $economics['overhead_cost'], 4),
                'revenue_total' => round((float) $record->revenue_total + (float) $economics['revenue'], 4),
                'profit_total' => round((float) $record->profit_total + (float) $economics['profit'], 4),
            ])->save();
        });
    }

    /**
     * Build a simple invoice for the active period.
     */
    public function generateCurrentPeriodInvoice(int $tenantId): ?BillingInvoice
    {
        $subscription = $this->activeSubscription($tenantId);

        if ($subscription === null) {
            return null;
        }

        [$periodStart, $periodEnd] = $this->periodRange($subscription);

        $usageRows = BillingUsageRecord::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->whereBetween('period_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->get();

        $totalMessages = (int) $usageRows->sum('messages_count');
        $bundleLimit = $this->messageBundleLimit($subscription);
        $overageCount = max(0, $totalMessages - max(0, $bundleLimit));
        $overageRate = $this->overageRate($subscription);
        $overageTotal = round($overageCount * $overageRate, 2);
        $subtotal = (float) ($subscription->plan?->monthly_price ?? 0);
        $grandTotal = round($subtotal + $overageTotal, 2);

        $invoice = BillingInvoice::query()
            ->withoutTenancy()
            ->create([
                'tenant_id' => $tenantId,
                'tenant_subscription_id' => $subscription->id,
                'invoice_number' => $this->nextInvoiceNumber($tenantId),
                'status' => 'issued',
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'subtotal' => $subtotal,
                'overage_total' => $overageTotal,
                'grand_total' => $grandTotal,
                'currency' => (string) ($subscription->tenant->currency ?? 'USD'),
                'issued_at' => now(),
                'metadata' => [
                    'messages_total' => $totalMessages,
                    'bundle_limit' => $bundleLimit,
                    'overage_messages' => $overageCount,
                ],
            ]);

        BillingInvoiceItem::query()->create([
            'billing_invoice_id' => $invoice->id,
            'type' => 'plan',
            'description' => 'Base monthly subscription',
            'quantity' => 1,
            'unit_price' => $subtotal,
            'total_price' => $subtotal,
        ]);

        if ($overageCount > 0) {
            BillingInvoiceItem::query()->create([
                'billing_invoice_id' => $invoice->id,
                'type' => 'overage',
                'description' => 'Message overage',
                'quantity' => $overageCount,
                'unit_price' => $overageRate,
                'total_price' => $overageTotal,
            ]);
        }

        return $invoice->load('items');
    }

    /**
     * Resolve active subscription for one tenant.
     */
    public function activeSubscription(int $tenantId): ?TenantSubscription
    {
        return TenantSubscription::query()
            ->withoutTenancy()
            ->with(['plan', 'tenant'])
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['active', 'trialing'])
            ->latest('id')
            ->first();
    }

    /**
     * Resolve billing period range.
     *
     * @return array{Carbon, Carbon}
     */
    public function periodRange(TenantSubscription $subscription): array
    {
        $start = $subscription->current_period_start?->copy()
            ?? now()->startOfMonth();

        $end = $subscription->current_period_end?->copy()
            ?? $start->copy()->endOfMonth();

        return [$start, $end];
    }

    private function messageBundleLimit(TenantSubscription $subscription): int
    {
        return (int) ($subscription->message_bundle_override
            ?? $subscription->plan?->message_bundle
            ?? 0);
    }

    private function overageRate(TenantSubscription $subscription): float
    {
        return (float) ($subscription->overage_price_override
            ?? $subscription->plan?->overage_price_per_message
            ?? 0);
    }

    private function nextInvoiceNumber(int $tenantId): string
    {
        $sequence = BillingInvoice::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->count() + 1;

        return sprintf('INV-%d-%06d', $tenantId, $sequence);
    }
}
