<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\HighRiskApproval;
use App\Models\Tenant;
use App\Models\User;
use App\Services\HighRiskApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApprovalController extends Controller
{
    /**
     * List high-risk approvals for active tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'approvals.view');
        $tenantId = $this->resolveTenantIdStrict($request);

        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in([
                HighRiskApproval::STATUS_PENDING,
                HighRiskApproval::STATUS_APPROVED,
                HighRiskApproval::STATUS_REJECTED,
                HighRiskApproval::STATUS_EXECUTED,
            ])],
            'requested_by' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = HighRiskApproval::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->with([
                'requester:id,name,email',
                'executor:id,name,email',
                'reviews.reviewer:id,name,email',
            ]);

        if (! empty($filters['action'])) {
            $query->where('action', trim((string) $filters['action']));
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['requested_by'])) {
            $query->where('requested_by', (int) $filters['requested_by']);
        }

        $rows = $query
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return response()->json($rows);
    }

    /**
     * Show one approval request with review trail.
     */
    public function show(Request $request, HighRiskApproval $highRiskApproval): JsonResponse
    {
        $this->authorizePermission($request, 'approvals.view');
        $tenantId = $this->resolveTenantIdStrict($request);
        $this->ensureTenant($highRiskApproval, $tenantId);

        return response()->json([
            'approval' => $highRiskApproval->load([
                'requester:id,name,email',
                'executor:id,name,email',
                'reviews.reviewer:id,name,email',
            ]),
        ]);
    }

    /**
     * Approve or reject one pending approval request.
     */
    public function review(
        Request $request,
        HighRiskApproval $highRiskApproval,
        HighRiskApprovalService $approvalService
    ): JsonResponse {
        $this->authorizePermission($request, 'approvals.review');
        $tenantId = $this->resolveTenantIdStrict($request);
        $this->ensureTenant($highRiskApproval, $tenantId);

        $payload = $request->validate([
            'approve' => ['required', 'boolean'],
            'comment' => ['nullable', 'string', 'max:4000'],
        ]);

        $user = $request->user();

        if (! $user instanceof User) {
            abort(401, 'Authentication is required.');
        }

        $updated = $approvalService->review(
            approval: $highRiskApproval,
            reviewer: $user,
            approve: (bool) $payload['approve'],
            comment: $payload['comment'] ?? null,
        );

        return response()->json([
            'message' => 'Approval review saved.',
            'approval' => $updated->load([
                'requester:id,name,email',
                'executor:id,name,email',
                'reviews.reviewer:id,name,email',
            ]),
        ]);
    }

    private function ensureTenant(HighRiskApproval $approval, int $tenantId): void
    {
        if ((int) $approval->tenant_id !== $tenantId) {
            abort(403, 'Approval does not belong to active tenant context.');
        }
    }

    private function resolveTenantIdStrict(Request $request): int
    {
        $tenantId = $this->resolveTenantIdForPermission($request, $request->user());

        if ($tenantId !== null && Tenant::query()->whereKey($tenantId)->exists()) {
            return $tenantId;
        }

        abort(422, 'Tenant context is required.');
    }
}
