<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Lead;
use App\Models\Tenant;
use App\Services\AttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /**
     * List attachments for one entity (lead/deal).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'leads.view');
        $tenant = $this->tenant($request);

        $payload = $request->validate([
            'entity_type' => ['required', Rule::in((array) config('attachments.entity_types', ['lead', 'deal']))],
            'entity_id' => ['required', 'integer', 'min:1'],
            'kind' => ['nullable', 'string', 'max:64'],
            'source' => ['nullable', 'string', 'max:64'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $entityType = mb_strtolower((string) $payload['entity_type']);
        $entityId = (int) $payload['entity_id'];
        $this->assertEntityAccess($tenant, $entityType, $entityId);

        $query = Attachment::query()
            ->withoutTenancy()
            ->with(['uploader:id,name,email'])
            ->where('tenant_id', (int) $tenant->id)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->orderByDesc('id');

        if (is_string($payload['kind'] ?? null) && trim((string) $payload['kind']) !== '') {
            $query->where('kind', trim((string) $payload['kind']));
        }

        if (is_string($payload['source'] ?? null) && trim((string) $payload['source']) !== '') {
            $query->where('source', trim((string) $payload['source']));
        }

        $perPage = (int) ($payload['per_page'] ?? 25);

        return response()->json([
            'attachments' => $query->paginate($perPage)->withQueryString(),
        ]);
    }

    /**
     * Upload one or more attachments for one entity.
     */
    public function store(Request $request, AttachmentService $attachmentService): JsonResponse
    {
        $this->authorizePermission($request, 'leads.update');
        $tenant = $this->tenant($request);
        $maxFiles = max(1, (int) config('attachments.max_files_per_request', 10));
        $maxKb = max(1, (int) config('attachments.max_file_size_kb', 10240));

        $payload = $request->validate([
            'entity_type' => ['required', Rule::in((array) config('attachments.entity_types', ['lead', 'deal']))],
            'entity_id' => ['required', 'integer', 'min:1'],
            'kind' => ['nullable', 'string', 'max:64'],
            'source' => ['nullable', 'string', 'max:64'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'meta' => ['nullable', 'array'],
            'files' => ['required', 'array', 'min:1', 'max:'.$maxFiles],
            'files.*' => ['required', 'file', 'max:'.$maxKb],
        ]);

        $entityType = mb_strtolower((string) $payload['entity_type']);
        $entityId = (int) $payload['entity_id'];
        $this->assertEntityAccess($tenant, $entityType, $entityId);

        $created = [];

        foreach ($request->file('files', []) as $file) {
            if (! $file instanceof \Illuminate\Http\UploadedFile) {
                continue;
            }

            $attachment = $attachmentService->storeUploadedFile($tenant, $file, [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'kind' => $payload['kind'] ?? 'document',
                'source' => $payload['source'] ?? 'manual',
                'title' => $payload['title'] ?? null,
                'description' => $payload['description'] ?? null,
                'meta' => $payload['meta'] ?? [],
                'uploaded_by' => optional($request->user())->id,
            ]);

            Activity::query()->withoutTenancy()->create([
                'tenant_id' => (int) $tenant->id,
                'actor_id' => optional($request->user())->id,
                'type' => 'attachment.uploaded',
                'subject_type' => Attachment::class,
                'subject_id' => (int) $attachment->id,
                'description' => 'Attachment uploaded.',
                'properties' => [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'original_name' => $attachment->original_name,
                    'mime_type' => $attachment->mime_type,
                    'size_bytes' => $attachment->size_bytes,
                    'scan_status' => $attachment->scan_status,
                ],
            ]);

            $created[] = $attachment->load('uploader:id,name,email');
        }

        return response()->json([
            'message' => 'Attachments uploaded successfully.',
            'attachments' => $created,
        ], 201);
    }

    /**
     * Download one attachment file.
     */
    public function download(Request $request, Attachment $attachment, AttachmentService $attachmentService): StreamedResponse
    {
        $this->authorizePermission($request, 'leads.view');
        $tenant = $this->tenant($request);
        $this->assertAttachmentScope($tenant, $attachment);

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => (int) $tenant->id,
            'actor_id' => optional($request->user())->id,
            'type' => 'attachment.downloaded',
            'subject_type' => Attachment::class,
            'subject_id' => (int) $attachment->id,
            'description' => 'Attachment downloaded.',
            'properties' => [
                'entity_type' => $attachment->entity_type,
                'entity_id' => $attachment->entity_id,
                'original_name' => $attachment->original_name,
            ],
        ]);

        return $attachmentService->downloadResponse($attachment);
    }

    /**
     * Delete one attachment file and metadata.
     */
    public function destroy(Request $request, Attachment $attachment, AttachmentService $attachmentService): JsonResponse
    {
        $this->authorizePermission($request, 'leads.update');
        $tenant = $this->tenant($request);
        $this->assertAttachmentScope($tenant, $attachment);

        $attachmentService->deleteAttachment($attachment);

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => (int) $tenant->id,
            'actor_id' => optional($request->user())->id,
            'type' => 'attachment.deleted',
            'subject_type' => Attachment::class,
            'subject_id' => (int) $attachment->id,
            'description' => 'Attachment deleted.',
            'properties' => [
                'entity_type' => $attachment->entity_type,
                'entity_id' => $attachment->entity_id,
                'original_name' => $attachment->original_name,
            ],
        ]);

        return response()->json([
            'message' => 'Attachment deleted successfully.',
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

    private function assertAttachmentScope(Tenant $tenant, Attachment $attachment): void
    {
        if ((int) $attachment->tenant_id !== (int) $tenant->id) {
            abort(404, 'Attachment not found in tenant scope.');
        }

        $this->assertEntityAccess($tenant, (string) $attachment->entity_type, (int) $attachment->entity_id);
    }

    private function assertEntityAccess(Tenant $tenant, string $entityType, int $entityId): void
    {
        if ($entityType !== 'lead') {
            return;
        }

        $exists = Lead::query()
            ->withoutTenancy()
            ->where('tenant_id', (int) $tenant->id)
            ->whereKey($entityId)
            ->exists();

        if (! $exists) {
            abort(404, 'Lead not found in tenant scope.');
        }
    }
}
