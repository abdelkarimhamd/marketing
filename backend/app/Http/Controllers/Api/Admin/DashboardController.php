<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\Segment;
use App\Models\Template;
use App\Models\WebhookInbox;
use App\Services\HealthMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Return aggregate dashboard metrics.
     */
    public function index(Request $request, HealthMetricsService $healthService): JsonResponse
    {
        $this->authorizePermission($request, 'dashboard.view');

        $metrics = [
            'leads_total' => Lead::query()->count(),
            'campaigns_total' => Campaign::query()->count(),
            'segments_total' => Segment::query()->count(),
            'templates_total' => Template::query()->count(),
            'messages_queued' => Message::query()->where('status', 'queued')->count(),
            'messages_sent' => Message::query()->whereIn('status', ['sent', 'delivered', 'opened', 'clicked', 'read'])->count(),
            'messages_failed' => Message::query()->where('status', 'failed')->count(),
            'webhooks_pending' => WebhookInbox::query()->where('status', 'pending')->count(),
        ];

        $recentActivities = Activity::query()
            ->with('actor:id,name,email')
            ->orderByDesc('id')
            ->limit(20)
            ->get([
                'id',
                'tenant_id',
                'actor_id',
                'type',
                'subject_type',
                'subject_id',
                'description',
                'properties',
                'created_at',
            ]);

        $tenantHealth = null;
        $tenantId = $request->attributes->get('tenant_id');

        if (is_int($tenantId) && $tenantId > 0) {
            $tenant = Tenant::query()->whereKey($tenantId)->first();

            if ($tenant !== null) {
                $tenantHealth = $healthService->tenantMetrics($tenant);
            }
        }

        return response()->json([
            'metrics' => $metrics,
            'recent_activities' => $recentActivities,
            'tenant_id' => $tenantId,
            'tenant_bypassed' => (bool) $request->attributes->get('tenant_bypassed', false),
            'tenant_health' => $tenantHealth,
        ]);
    }
}
