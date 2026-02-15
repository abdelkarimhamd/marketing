<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillingInvoice;
use App\Models\BillingPlan;
use App\Models\BillingUsageRecord;
use App\Models\Campaign;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Services\BillingService;
use App\Services\ProfitabilityReportingService;
use App\Services\WorkspaceAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BillingController extends Controller
{
    /**
     * List all active billing plans.
     */
    public function plans(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'settings.view', requireTenantContext: false);

        return response()->json([
            'plans' => BillingPlan::query()->orderBy('monthly_price')->get(),
        ]);
    }

    /**
     * Create or update a billing plan (super admin).
     */
    public function savePlan(Request $request, ?BillingPlan $billingPlan = null): JsonResponse
    {
        $this->authorizePermission($request, 'settings.update', requireTenantContext: false);

        if (! $request->user()?->isSuperAdmin()) {
            abort(403, 'Only super-admin can manage plan catalog.');
        }

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:120', Rule::unique('billing_plans', 'slug')->ignore($billingPlan?->id)],
            'seat_limit' => ['nullable', 'integer', 'min:1'],
            'message_bundle' => ['nullable', 'integer', 'min:0'],
            'monthly_price' => ['nullable', 'numeric', 'min:0'],
            'overage_price_per_message' => ['nullable', 'numeric', 'min:0'],
            'hard_limit' => ['nullable', 'boolean'],
            'addons' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $plan = ($billingPlan ?? new BillingPlan())->fill([
            'name' => $payload['name'],
            'slug' => $payload['slug'],
            'seat_limit' => $payload['seat_limit'] ?? 1,
            'message_bundle' => $payload['message_bundle'] ?? 0,
            'monthly_price' => $payload['monthly_price'] ?? 0,
            'overage_price_per_message' => $payload['overage_price_per_message'] ?? 0,
            'hard_limit' => $payload['hard_limit'] ?? false,
            'addons' => $payload['addons'] ?? [],
            'is_active' => $payload['is_active'] ?? true,
        ]);
        $plan->save();

        return response()->json([
            'message' => 'Billing plan saved.',
            'plan' => $plan,
        ], $billingPlan ? 200 : 201);
    }

    /**
     * Show one tenant subscription summary.
     */
    public function subscription(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'settings.view', requireTenantContext: false);
        $tenantId = $this->resolveTenantId($request);

        $tenant = Tenant::query()->whereKey($tenantId)->firstOrFail();
        $subscription = TenantSubscription::query()
            ->withoutTenancy()
            ->with('plan')
            ->where('tenant_id', $tenant->id)
            ->latest('id')
            ->first();

        return response()->json([
            'tenant' => $tenant->only(['id', 'name', 'slug', 'currency']),
            'subscription' => $subscription,
        ]);
    }

    /**
     * Upsert tenant subscription.
     */
    public function saveSubscription(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'settings.update', requireTenantContext: false);
        $tenantId = $this->resolveTenantId($request);

        $payload = $request->validate([
            'billing_plan_id' => ['nullable', 'integer', 'exists:billing_plans,id'],
            'status' => ['nullable', Rule::in(['trialing', 'active', 'past_due', 'cancelled'])],
            'seat_limit_override' => ['nullable', 'integer', 'min:1'],
            'message_bundle_override' => ['nullable', 'integer', 'min:0'],
            'overage_price_override' => ['nullable', 'numeric', 'min:0'],
            'current_period_start' => ['nullable', 'date'],
            'current_period_end' => ['nullable', 'date'],
            'provider' => ['nullable', 'string', 'max:64'],
            'provider_subscription_id' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ]);

        $subscription = TenantSubscription::query()
            ->withoutTenancy()
            ->updateOrCreate(
                ['tenant_id' => $tenantId],
                [
                    'billing_plan_id' => $payload['billing_plan_id'] ?? null,
                    'status' => $payload['status'] ?? 'active',
                    'seat_limit_override' => $payload['seat_limit_override'] ?? null,
                    'message_bundle_override' => $payload['message_bundle_override'] ?? null,
                    'overage_price_override' => $payload['overage_price_override'] ?? null,
                    'current_period_start' => $payload['current_period_start'] ?? now()->startOfMonth(),
                    'current_period_end' => $payload['current_period_end'] ?? now()->endOfMonth(),
                    'provider' => $payload['provider'] ?? 'manual',
                    'provider_subscription_id' => $payload['provider_subscription_id'] ?? null,
                    'metadata' => $payload['metadata'] ?? [],
                ]
            );

        return response()->json([
            'message' => 'Subscription saved.',
            'subscription' => $subscription->load('plan'),
        ]);
    }

    /**
     * List tenant usage records.
     */
    public function usage(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'settings.view', requireTenantContext: false);
        $tenantId = $this->resolveTenantId($request);
        $rows = BillingUsageRecord::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('period_date')
            ->paginate((int) $request->input('per_page', 50))
            ->withQueryString();

        return response()->json($rows);
    }

    /**
     * Profitability report grouped by tenant/campaign/channel.
     */
    public function profitability(
        Request $request,
        ProfitabilityReportingService $profitabilityReportingService
    ): JsonResponse {
        $this->authorizePermission($request, 'settings.view', requireTenantContext: false);
        $tenantId = $this->resolveOptionalTenantId($request);

        $filters = $request->validate([
            'channel' => ['nullable', Rule::in(['email', 'sms', 'whatsapp'])],
            'campaign_id' => ['nullable', 'integer', 'exists:campaigns,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        if (isset($filters['campaign_id']) && $tenantId !== null) {
            $campaignExists = Campaign::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->whereKey((int) $filters['campaign_id'])
                ->exists();

            if (! $campaignExists) {
                abort(422, 'campaign_id does not belong to active tenant context.');
            }
        }

        $report = $profitabilityReportingService->report($tenantId, $filters);

        return response()->json($report);
    }

    /**
     * Low-margin profitability alerts.
     */
    public function marginAlerts(
        Request $request,
        ProfitabilityReportingService $profitabilityReportingService
    ): JsonResponse {
        $this->authorizePermission($request, 'settings.view', requireTenantContext: false);
        $tenantId = $this->resolveOptionalTenantId($request);

        $filters = $request->validate([
            'channel' => ['nullable', Rule::in(['email', 'sms', 'whatsapp'])],
            'campaign_id' => ['nullable', 'integer', 'exists:campaigns,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'threshold_percent' => ['nullable', 'numeric', 'min:-100', 'max:100'],
        ]);

        if (isset($filters['campaign_id']) && $tenantId !== null) {
            $campaignExists = Campaign::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->whereKey((int) $filters['campaign_id'])
                ->exists();

            if (! $campaignExists) {
                abort(422, 'campaign_id does not belong to active tenant context.');
            }
        }

        $alerts = $profitabilityReportingService->marginAlerts($tenantId, $filters);

        return response()->json($alerts);
    }

    /**
     * Workspace analytics for super-admin cross-tenant comparisons.
     */
    public function workspaceAnalytics(
        Request $request,
        WorkspaceAnalyticsService $workspaceAnalyticsService
    ): JsonResponse {
        $this->authorizePermission($request, 'settings.view', requireTenantContext: false);

        if (! $request->user()?->isSuperAdmin()) {
            abort(403, 'Workspace analytics is restricted to super-admin.');
        }

        $filters = $this->resolveWorkspaceFilters($request);

        return response()->json($workspaceAnalyticsService->report($filters));
    }

    /**
     * Export cross-tenant workspace analytics as CSV for BI ingestion.
     */
    public function workspaceAnalyticsExport(
        Request $request,
        WorkspaceAnalyticsService $workspaceAnalyticsService
    ) {
        $this->authorizePermission($request, 'settings.view', requireTenantContext: false);

        if (! $request->user()?->isSuperAdmin()) {
            abort(403, 'Workspace analytics export is restricted to super-admin.');
        }

        $filters = $this->resolveWorkspaceFilters($request);
        $export = $workspaceAnalyticsService->exportToCsv($filters);
        $filename = (string) ($export['filename'] ?? 'workspace-analytics.csv');
        $csv = (string) ($export['csv'] ?? '');

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * List tenant invoices.
     */
    public function invoices(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'settings.view', requireTenantContext: false);
        $tenantId = $this->resolveTenantId($request);

        $rows = BillingInvoice::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->with('items')
            ->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 20))
            ->withQueryString();

        return response()->json($rows);
    }

    /**
     * Generate current-period invoice now.
     */
    public function generateInvoice(Request $request, BillingService $billingService): JsonResponse
    {
        $this->authorizePermission($request, 'settings.update', requireTenantContext: false);
        $tenantId = $this->resolveTenantId($request);

        $invoice = $billingService->generateCurrentPeriodInvoice($tenantId);

        if ($invoice === null) {
            return response()->json([
                'message' => 'No active subscription found.',
            ], 422);
        }

        return response()->json([
            'message' => 'Invoice generated successfully.',
            'invoice' => $invoice,
        ], 201);
    }

    private function resolveTenantId(Request $request): int
    {
        $tenantId = $request->attributes->get('tenant_id');

        if (is_int($tenantId) && $tenantId > 0) {
            return $tenantId;
        }

        $requested = $request->query('tenant_id', $request->input('tenant_id'));

        if (is_numeric($requested) && (int) $requested > 0) {
            return (int) $requested;
        }

        abort(422, 'Tenant context is required.');
    }

    private function resolveOptionalTenantId(Request $request): ?int
    {
        $tenantId = $request->attributes->get('tenant_id');

        if (is_int($tenantId) && $tenantId > 0) {
            return $tenantId;
        }

        $requested = $request->query('tenant_id', $request->input('tenant_id'));

        if (is_numeric($requested) && (int) $requested > 0) {
            return (int) $requested;
        }

        if ($request->user()?->isSuperAdmin()) {
            return null;
        }

        return $this->resolveTenantId($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveWorkspaceFilters(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'channel' => ['nullable', Rule::in(['email', 'sms', 'whatsapp'])],
            'tenant_ids' => ['nullable'],
        ]);

        $tenantIdsInput = $request->query('tenant_ids', $request->input('tenant_ids'));
        $tenantIds = [];

        if (is_string($tenantIdsInput) && trim($tenantIdsInput) !== '') {
            $tenantIds = array_values(array_filter(array_map(
                static fn (string $value): ?int => is_numeric(trim($value)) ? (int) trim($value) : null,
                explode(',', $tenantIdsInput)
            ), static fn (?int $value): bool => $value !== null && $value > 0));
        } elseif (is_array($tenantIdsInput)) {
            $tenantIds = array_values(array_filter(array_map(
                static fn (mixed $value): ?int => is_numeric($value) ? (int) $value : null,
                $tenantIdsInput
            ), static fn (?int $value): bool => $value !== null && $value > 0));
        }

        if ($tenantIds !== []) {
            $existingCount = Tenant::query()->whereIn('id', $tenantIds)->count();

            if ($existingCount !== count(array_unique($tenantIds))) {
                abort(422, 'One or more tenant_ids are invalid.');
            }
        }

        $validated['tenant_ids'] = $tenantIds;

        return $validated;
    }
}
