<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DataQualityRun;
use App\Models\MergeSuggestion;
use App\Services\DataQualityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DataQualityController extends Controller
{
    /**
     * List data quality runs.
     */
    public function runs(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'data_quality.view');
        $tenantId = $this->tenantId($request);

        $payload = $request->validate([
            'status' => ['nullable', 'string', 'max:40'],
            'run_type' => ['nullable', 'string', 'max:40'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = DataQualityRun::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->with(['requester:id,name,email']);

        if (is_string($payload['status'] ?? null) && trim((string) $payload['status']) !== '') {
            $query->where('status', trim((string) $payload['status']));
        }

        if (is_string($payload['run_type'] ?? null) && trim((string) $payload['run_type']) !== '') {
            $query->where('run_type', trim((string) $payload['run_type']));
        }

        return response()->json(
            $query->orderByDesc('id')
                ->paginate((int) ($payload['per_page'] ?? 20))
                ->withQueryString()
        );
    }

    /**
     * Queue a new data quality run.
     */
    public function start(Request $request, DataQualityService $service): JsonResponse
    {
        $this->authorizePermission($request, 'data_quality.create');
        $tenantId = $this->tenantId($request);

        $payload = $request->validate([
            'run_type' => ['nullable', 'string', 'max:40'],
        ]);

        $run = $service->queueRun(
            tenantId: $tenantId,
            requestedBy: $request->user()?->id,
            runType: is_string($payload['run_type'] ?? null) ? trim((string) $payload['run_type']) : 'full',
        );

        return response()->json([
            'message' => 'Data quality run queued.',
            'run' => $run,
        ], 202);
    }

    /**
     * List merge suggestions.
     */
    public function suggestions(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'data_quality.view');
        $tenantId = $this->tenantId($request);

        $payload = $request->validate([
            'status' => ['nullable', 'string', 'max:40'],
            'min_confidence' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = MergeSuggestion::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->with([
                'candidateA:id,first_name,last_name,email,phone,score,status',
                'candidateB:id,first_name,last_name,email,phone,score,status',
                'reviewer:id,name,email',
            ]);

        if (is_string($payload['status'] ?? null) && trim((string) $payload['status']) !== '') {
            $query->where('status', trim((string) $payload['status']));
        }

        if (is_numeric($payload['min_confidence'] ?? null)) {
            $query->where('confidence', '>=', (float) $payload['min_confidence']);
        }

        return response()->json(
            $query->orderByDesc('confidence')
                ->orderByDesc('id')
                ->paginate((int) ($payload['per_page'] ?? 30))
                ->withQueryString()
        );
    }

    /**
     * Review merge suggestion status.
     */
    public function review(
        Request $request,
        MergeSuggestion $mergeSuggestion,
        DataQualityService $service
    ): JsonResponse {
        $this->authorizePermission($request, 'data_quality.review');
        $tenantId = $this->tenantId($request);

        if ((int) $mergeSuggestion->tenant_id !== $tenantId) {
            abort(404, 'Merge suggestion not found in tenant scope.');
        }

        $payload = $request->validate([
            'status' => ['required', 'string', 'max:40'],
        ]);

        $updated = $service->reviewSuggestion(
            suggestion: $mergeSuggestion,
            status: trim((string) $payload['status']),
            reviewedBy: $request->user()?->id,
        );

        return response()->json([
            'message' => 'Merge suggestion updated.',
            'suggestion' => $updated->load([
                'candidateA:id,first_name,last_name,email,phone,score,status',
                'candidateB:id,first_name,last_name,email,phone,score,status',
                'reviewer:id,name,email',
            ]),
        ]);
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
