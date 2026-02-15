<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExportJob;
use App\Models\HighRiskApproval;
use App\Models\User;
use App\Services\BiExportService;
use App\Services\HighRiskApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ExportController extends Controller
{
    /**
     * Trigger immediate CSV export for BI/reporting.
     */
    public function run(
        Request $request,
        BiExportService $exportService,
        HighRiskApprovalService $approvalService
    ): JsonResponse
    {
        $this->authorizePermission($request, 'leads.export');
        $tenantId = $this->tenantId($request);

        $payload = $request->validate([
            'type' => ['required', Rule::in(['leads', 'messages', 'events'])],
            'approval_id' => ['nullable', 'integer', 'min:1'],
            'approval_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $request->user();

        if (! $user instanceof User) {
            abort(401, 'Authentication is required.');
        }

        $rowCount = $exportService->estimateRowCount($tenantId, $payload['type']);
        $approvalDecision = $approvalService->authorizeOrRequest(
            tenantId: $tenantId,
            actor: $user,
            action: 'leads.export',
            subjectType: ExportJob::class,
            subjectId: null,
            payload: [
                'type' => (string) $payload['type'],
                'row_count' => $rowCount,
                'destination' => 'download',
            ],
            approvalId: isset($payload['approval_id']) ? (int) $payload['approval_id'] : null,
            reason: $payload['approval_reason'] ?? null,
        );

        if (! ($approvalDecision['execute'] ?? false)) {
            return response()->json([
                'message' => 'Export requires approval before execution.',
                'requires_approval' => true,
                'approval' => $approvalDecision['approval'] ?? null,
            ], 202);
        }

        $job = $exportService->exportToCsv($tenantId, $payload['type']);
        $approval = $approvalDecision['approval'] ?? null;

        if ($approval instanceof HighRiskApproval) {
            $approvalService->markExecuted(
                approval: $approval,
                executedBy: (int) $user->id,
                executionMeta: [
                    'action' => 'leads.export',
                    'type' => (string) $payload['type'],
                    'row_count' => $rowCount,
                    'export_job_id' => (int) $job->id,
                ],
            );
        }

        return response()->json([
            'message' => 'Export completed.',
            'job' => $job,
            'download_url' => route('admin.exports.download', ['exportJob' => $job->id]),
            'approval_id' => $approval instanceof HighRiskApproval ? (int) $approval->id : null,
        ], 201);
    }

    /**
     * List export jobs.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'leads.export');
        $tenantId = $this->tenantId($request);

        $rows = ExportJob::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 20))
            ->withQueryString();

        return response()->json($rows);
    }

    /**
     * Return local download URL metadata.
     */
    public function download(Request $request, ExportJob $exportJob): JsonResponse
    {
        $this->authorizePermission($request, 'leads.export');
        $tenantId = $this->tenantId($request);

        if ((int) $exportJob->tenant_id !== $tenantId) {
            abort(404, 'Export job not found in tenant scope.');
        }

        if (! is_string($exportJob->file_path) || $exportJob->file_path === '' || ! Storage::disk('local')->exists($exportJob->file_path)) {
            abort(404, 'Export file not available.');
        }

        return response()->json([
            'job' => $exportJob,
            'file_path' => $exportJob->file_path,
            'download_url' => route('admin.exports.stream', ['exportJob' => $exportJob->id]),
        ]);
    }

    /**
     * Stream export file contents.
     */
    public function stream(Request $request, ExportJob $exportJob)
    {
        $this->authorizePermission($request, 'leads.export');
        $tenantId = $this->tenantId($request);

        if ((int) $exportJob->tenant_id !== $tenantId) {
            abort(404, 'Export job not found in tenant scope.');
        }

        if (! is_string($exportJob->file_path) || $exportJob->file_path === '' || ! Storage::disk('local')->exists($exportJob->file_path)) {
            abort(404, 'Export file not available.');
        }

        return Storage::disk('local')->download(
            $exportJob->file_path,
            basename($exportJob->file_path)
        );
    }

    private function tenantId(Request $request): int
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
}
