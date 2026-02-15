<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\Campaign;
use App\Models\Segment;
use App\Models\Template;
use App\Models\WorkflowVersion;
use App\Services\WorkflowVersioningService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkflowController extends Controller
{
    /**
     * List workflow versions in tenant scope.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'campaigns.view');

        $payload = $request->validate([
            'subject_type' => ['nullable', Rule::in(['template', 'segment', 'campaign'])],
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = WorkflowVersion::query()->with(['creator:id,name', 'approver:id,name']);

        if (! empty($payload['subject_type'])) {
            $query->where('subject_type', $this->subjectClass($payload['subject_type']));
        }

        if (! empty($payload['subject_id'])) {
            $query->where('subject_id', (int) $payload['subject_id']);
        }

        $rows = $query->orderByDesc('id')
            ->paginate((int) ($payload['per_page'] ?? 25))
            ->withQueryString();

        return response()->json($rows);
    }

    /**
     * Create draft snapshot from current subject state.
     */
    public function snapshot(Request $request, WorkflowVersioningService $versioningService): JsonResponse
    {
        $this->authorizePermission($request, 'campaigns.update');

        $payload = $request->validate([
            'subject_type' => ['required', Rule::in(['template', 'segment', 'campaign'])],
            'subject_id' => ['required', 'integer', 'min:1'],
        ]);

        $subject = $this->resolveSubject($payload['subject_type'], (int) $payload['subject_id']);
        $version = $versioningService->snapshot(
            subject: $subject,
            tenantId: (int) $subject->tenant_id,
            createdBy: $request->user()?->id,
            status: 'draft',
        );

        return response()->json([
            'message' => 'Workflow version created.',
            'version' => $version,
        ], 201);
    }

    /**
     * Submit version for manager approval.
     */
    public function requestApproval(
        Request $request,
        WorkflowVersion $workflowVersion,
        WorkflowVersioningService $versioningService
    ): JsonResponse {
        $this->authorizePermission($request, 'campaigns.update');

        $approval = $versioningService->requestApproval(
            version: $workflowVersion,
            requestedBy: $request->user()?->id,
            comment: $request->input('comment'),
        );

        return response()->json([
            'message' => 'Approval requested.',
            'approval_request' => $approval,
        ], 201);
    }

    /**
     * Approve or reject approval request.
     */
    public function review(
        Request $request,
        ApprovalRequest $approvalRequest,
        WorkflowVersioningService $versioningService
    ): JsonResponse {
        $this->authorizePermission($request, 'campaigns.update');

        $payload = $request->validate([
            'approve' => ['required', 'boolean'],
            'comment' => ['nullable', 'string', 'max:4000'],
        ]);

        $updated = $versioningService->review(
            request: $approvalRequest,
            approve: (bool) $payload['approve'],
            reviewerId: $request->user()?->id,
            comment: $payload['comment'] ?? null,
        );

        return response()->json([
            'message' => 'Approval review saved.',
            'approval_request' => $updated,
        ]);
    }

    /**
     * Publish one approved version.
     */
    public function publish(
        Request $request,
        WorkflowVersion $workflowVersion,
        WorkflowVersioningService $versioningService
    ): JsonResponse {
        $this->authorizePermission($request, 'campaigns.send');
        $version = $versioningService->publish($workflowVersion);

        return response()->json([
            'message' => 'Workflow version published.',
            'version' => $version,
        ]);
    }

    /**
     * Rollback to selected version and create new published snapshot.
     */
    public function rollback(
        Request $request,
        WorkflowVersion $workflowVersion,
        WorkflowVersioningService $versioningService
    ): JsonResponse {
        $this->authorizePermission($request, 'campaigns.update');
        $version = $versioningService->rollback(
            targetVersion: $workflowVersion,
            actorId: $request->user()?->id,
        );

        return response()->json([
            'message' => 'Rollback completed.',
            'version' => $version,
        ]);
    }

    private function resolveSubject(string $type, int $subjectId): Model
    {
        $class = $this->subjectClass($type);

        $subject = $class::query()->whereKey($subjectId)->first();

        if ($subject === null) {
            abort(404, 'Workflow subject not found.');
        }

        return $subject;
    }

    /**
     * @return class-string<Model>
     */
    private function subjectClass(string $type): string
    {
        return match ($type) {
            'template' => Template::class,
            'segment' => Segment::class,
            'campaign' => Campaign::class,
            default => abort(422, 'Unsupported workflow subject type.'),
        };
    }
}

