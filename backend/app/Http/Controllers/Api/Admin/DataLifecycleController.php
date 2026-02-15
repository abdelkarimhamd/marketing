<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\HighRiskApproval;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Services\HighRiskApprovalService;
use App\Services\RetentionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DataLifecycleController extends Controller
{
    /**
     * Run retention archive process for current tenant.
     */
    public function archive(Request $request, RetentionService $retentionService): JsonResponse
    {
        $this->authorizePermission($request, 'settings.update', requireTenantContext: false);
        $tenant = $this->tenant($request);

        $payload = $request->validate([
            'months' => ['nullable', 'integer', 'min:1', 'max:120'],
        ]);

        $result = $retentionService->archiveForTenant($tenant, $payload['months'] ?? null);

        return response()->json([
            'message' => 'Archive process completed.',
            'result' => $result,
        ]);
    }

    /**
     * Export one lead data payload for right-to-access.
     */
    public function exportLead(Request $request, Lead $lead, RetentionService $retentionService): JsonResponse
    {
        $this->authorizePermission($request, 'leads.export');
        $tenant = $this->tenant($request);

        if ((int) $lead->tenant_id !== (int) $tenant->id) {
            abort(404, 'Lead not found in tenant scope.');
        }

        $data = $retentionService->exportLeadData((int) $tenant->id, (int) $lead->id);

        return response()->json([
            'lead' => $data,
        ]);
    }

    /**
     * Delete/anonymize lead data for right-to-delete.
     */
    public function deleteLead(
        Request $request,
        Lead $lead,
        RetentionService $retentionService,
        HighRiskApprovalService $approvalService
    ): JsonResponse
    {
        $this->authorizePermission($request, 'leads.delete');
        $tenant = $this->tenant($request);

        if ((int) $lead->tenant_id !== (int) $tenant->id) {
            abort(404, 'Lead not found in tenant scope.');
        }

        $payload = $request->validate([
            'hard_delete' => ['nullable', 'boolean'],
            'approval_id' => ['nullable', 'integer', 'min:1'],
            'approval_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $request->user();

        if (! $user instanceof User) {
            abort(401, 'Authentication is required.');
        }

        $hardDelete = (bool) ($payload['hard_delete'] ?? false);
        $approvalDecision = $approvalService->authorizeOrRequest(
            tenantId: (int) $tenant->id,
            actor: $user,
            action: 'lead.delete',
            subjectType: Lead::class,
            subjectId: (int) $lead->id,
            payload: [
                'lead_id' => (int) $lead->id,
                'hard_delete' => $hardDelete,
                'email' => $lead->email,
            ],
            approvalId: isset($payload['approval_id']) ? (int) $payload['approval_id'] : null,
            reason: $payload['approval_reason'] ?? null,
        );

        if (! ($approvalDecision['execute'] ?? false)) {
            return response()->json([
                'message' => 'Lead deletion requires approval before execution.',
                'requires_approval' => true,
                'approval' => $approvalDecision['approval'] ?? null,
            ], 202);
        }

        $ok = $retentionService->deleteLeadData((int) $tenant->id, (int) $lead->id, $hardDelete);
        $approval = $approvalDecision['approval'] ?? null;

        if ($ok && $approval instanceof HighRiskApproval) {
            $approvalService->markExecuted(
                approval: $approval,
                executedBy: (int) $user->id,
                executionMeta: [
                    'action' => 'lead.delete',
                    'lead_id' => (int) $lead->id,
                    'hard_delete' => $hardDelete,
                ],
            );
        }

        return response()->json([
            'message' => $ok ? 'Lead data removed.' : 'Lead was not found.',
            'hard_delete' => $hardDelete,
            'approval_id' => $approval instanceof HighRiskApproval ? (int) $approval->id : null,
        ]);
    }

    private function tenant(Request $request): Tenant
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
}
