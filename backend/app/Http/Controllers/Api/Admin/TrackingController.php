<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\TrackingEvent;
use App\Services\TrackingIngestionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrackingController extends Controller
{
    /**
     * Lead web activity timeline.
     */
    public function leadEvents(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizePermission($request, 'tracking.view');
        $tenantId = $this->tenantId($request);

        if ((int) $lead->tenant_id !== $tenantId) {
            abort(404, 'Lead not found in tenant scope.');
        }

        $payload = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $events = TrackingEvent::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('lead_id', (int) $lead->id)
            ->orderByDesc('occurred_at')
            ->paginate((int) ($payload['per_page'] ?? 50))
            ->withQueryString();

        return response()->json($events);
    }

    /**
     * Aggregated tracking analytics.
     */
    public function analytics(Request $request, TrackingIngestionService $tracking): JsonResponse
    {
        $this->authorizePermission($request, 'tracking.view');
        $tenantId = $this->tenantId($request);

        $payload = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $from = isset($payload['from'])
            ? Carbon::parse((string) $payload['from'])->startOfDay()
            : now()->subDays(30)->startOfDay();

        $to = isset($payload['to'])
            ? Carbon::parse((string) $payload['to'])->endOfDay()
            : now()->endOfDay();

        return response()->json($tracking->analytics($tenantId, $from, $to));
    }

    private function tenantId(Request $request): int
    {
        $tenantId = $this->resolveTenantIdForPermission($request, $request->user());

        if ($tenantId === null || $tenantId <= 0) {
            abort(422, 'Tenant context is required.');
        }

        return $tenantId;
    }
}
