<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\HealthMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HealthController extends Controller
{
    /**
     * Return health metrics for current tenant.
     */
    public function tenant(Request $request, HealthMetricsService $healthService): JsonResponse
    {
        $this->authorizePermission($request, 'dashboard.view', requireTenantContext: false);
        $tenant = $this->resolveTenant($request);
        $metrics = $healthService->tenantMetrics($tenant);

        return response()->json([
            'metrics' => $metrics,
        ]);
    }

    /**
     * Super-admin tenant console with health summaries.
     */
    public function tenantConsole(Request $request, HealthMetricsService $healthService): JsonResponse
    {
        $this->authorizePermission($request, 'dashboard.view', requireTenantContext: false);

        if (! $request->user()?->isSuperAdmin()) {
            abort(403, 'Tenant console is restricted to super-admin.');
        }

        $selectedTenantId = $this->resolveSelectedTenantId($request);
        $tenantQuery = Tenant::query()->orderBy('name');

        if ($selectedTenantId !== null) {
            $tenantQuery->whereKey($selectedTenantId);
        }

        $tenants = $tenantQuery->get();
        $rows = [];

        foreach ($tenants as $tenant) {
            $rows[] = array_merge(
                $tenant->only(['id', 'name', 'slug', 'is_active']),
                $healthService->tenantConsoleMetrics($tenant),
            );
        }

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'tenant_id' => $selectedTenantId,
            'summary' => $this->buildConsoleSummary($rows),
            'tenants' => $rows,
        ]);
    }

    private function resolveTenant(Request $request): Tenant
    {
        $tenantId = $request->attributes->get('tenant_id');

        if (! is_int($tenantId) || $tenantId <= 0) {
            $requested = $request->query('tenant_id', $request->input('tenant_id'));

            if (is_numeric($requested) && (int) $requested > 0) {
                $tenantId = (int) $requested;
            }
        }

        if (! is_int($tenantId) || $tenantId <= 0) {
            abort(422, 'Tenant context is required.');
        }

        return Tenant::query()->whereKey($tenantId)->firstOrFail();
    }

    private function resolveSelectedTenantId(Request $request): ?int
    {
        $tenantId = $request->attributes->get('tenant_id');

        if (is_int($tenantId) && $tenantId > 0) {
            return $tenantId;
        }

        $requested = $request->query('tenant_id', $request->input('tenant_id'));

        if (is_numeric($requested) && (int) $requested > 0) {
            return (int) $requested;
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function buildConsoleSummary(array $rows): array
    {
        $summary = [
            'tenants_total' => count($rows),
            'tenants_with_issues' => 0,
            'tenants_with_critical' => 0,
            'tenants_with_automation_errors' => 0,
            'tenants_with_integration_issues' => 0,
            'tenants_with_domain_issues' => 0,
            'critical_alerts_total' => 0,
            'warning_alerts_total' => 0,
            'info_alerts_total' => 0,
        ];

        foreach ($rows as $row) {
            $alerts = is_array($row['alerts'] ?? null) ? $row['alerts'] : [];
            $critical = (int) ($alerts['critical'] ?? 0);
            $warning = (int) ($alerts['warning'] ?? 0);
            $info = (int) ($alerts['info'] ?? 0);
            $total = (int) ($alerts['total'] ?? ($critical + $warning + $info));

            if ($total > 0) {
                $summary['tenants_with_issues']++;
            }

            if ($critical > 0) {
                $summary['tenants_with_critical']++;
            }

            $summary['critical_alerts_total'] += $critical;
            $summary['warning_alerts_total'] += $warning;
            $summary['info_alerts_total'] += $info;

            $automation = is_array(data_get($row, 'diagnostics.automation')) ? data_get($row, 'diagnostics.automation') : [];
            $integration = is_array(data_get($row, 'diagnostics.integrations')) ? data_get($row, 'diagnostics.integrations') : [];
            $domains = is_array(data_get($row, 'diagnostics.domains')) ? data_get($row, 'diagnostics.domains') : [];

            $automationErrors = (int) ($automation['failed_messages_last_24h'] ?? 0)
                + (int) ($automation['campaign_failures_last_24h'] ?? 0)
                + (int) ($automation['queued_stale_messages'] ?? 0)
                + (int) ($automation['webhooks_failed_last_24h'] ?? 0)
                + (int) ($automation['webhooks_pending_stale'] ?? 0);

            if ($automationErrors > 0) {
                $summary['tenants_with_automation_errors']++;
            }

            $integrationIssues = (int) ($integration['failing_connections'] ?? 0)
                + (int) ($integration['stale_connections'] ?? 0)
                + (int) ($integration['failing_subscriptions'] ?? 0)
                + (int) ($integration['stale_subscriptions'] ?? 0);

            if ($integrationIssues > 0) {
                $summary['tenants_with_integration_issues']++;
            }

            $domainIssues = (int) ($domains['verification_pending'] ?? 0)
                + (int) ($domains['verification_failed'] ?? 0)
                + (int) ($domains['ssl_pending'] ?? 0)
                + (int) ($domains['ssl_failed'] ?? 0);

            if ($domainIssues > 0) {
                $summary['tenants_with_domain_issues']++;
            }
        }

        return $summary;
    }
}
