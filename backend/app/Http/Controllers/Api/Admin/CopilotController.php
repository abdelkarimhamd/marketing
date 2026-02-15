<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Services\CopilotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CopilotController extends Controller
{
    /**
     * Show lead copilot panel data.
     */
    public function show(Request $request, Lead $lead, CopilotService $copilot): JsonResponse
    {
        $this->authorizePermission($request, 'copilot.view');
        $tenantId = $this->tenantId($request);

        if ((int) $lead->tenant_id !== $tenantId) {
            abort(404, 'Lead not found in tenant scope.');
        }

        return response()->json($copilot->panelData($lead));
    }

    /**
     * Generate or refresh lead copilot artifacts.
     */
    public function generate(Request $request, Lead $lead, CopilotService $copilot): JsonResponse
    {
        $this->authorizePermission($request, 'copilot.create');
        $tenantId = $this->tenantId($request);

        if ((int) $lead->tenant_id !== $tenantId) {
            abort(404, 'Lead not found in tenant scope.');
        }

        $payload = $request->validate([
            'sync' => ['nullable', 'boolean'],
        ]);

        if ((bool) ($payload['sync'] ?? false)) {
            $generated = $copilot->generateNow($lead, $request->user()?->id);

            return response()->json([
                'message' => 'Copilot generated successfully.',
                'summary' => $generated['summary'],
                'recommendations' => $generated['recommendations'],
            ]);
        }

        $copilot->dispatchGenerate($tenantId, (int) $lead->id, $request->user()?->id);

        return response()->json([
            'message' => 'Copilot generation queued.',
        ], 202);
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
