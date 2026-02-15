<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Tenant;
use App\Services\AttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaLibraryController extends Controller
{
    private const ENTITY_TYPE = 'media_library';

    /**
     * List tenant media assets.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'templates.view');
        $tenant = $this->tenant($request);

        $payload = $request->validate([
            'kind' => ['nullable', Rule::in(['image', 'video', 'audio', 'document', 'other'])],
            'source' => ['nullable', 'string', 'max:64'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Attachment::query()
            ->withoutTenancy()
            ->with(['uploader:id,name,email'])
            ->where('tenant_id', (int) $tenant->id)
            ->where('entity_type', self::ENTITY_TYPE)
            ->orderByDesc('id');

        if (is_string($payload['kind'] ?? null) && trim((string) $payload['kind']) !== '') {
            $query->where('kind', trim((string) $payload['kind']));
        }

        if (is_string($payload['source'] ?? null) && trim((string) $payload['source']) !== '') {
            $query->where('source', trim((string) $payload['source']));
        }

        if (is_string($payload['search'] ?? null) && trim((string) $payload['search']) !== '') {
            $search = trim((string) $payload['search']);
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('original_name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $perPage = (int) ($payload['per_page'] ?? 25);

        return response()->json([
            'assets' => $query->paginate($perPage)->withQueryString(),
        ]);
    }

    /**
     * Upload media assets to tenant library.
     */
    public function store(Request $request, AttachmentService $attachmentService): JsonResponse
    {
        $this->authorizePermission($request, 'templates.update');
        $tenant = $this->tenant($request);
        $maxFiles = max(1, (int) config('attachments.max_files_per_request', 10));
        $maxKb = max(1, (int) config('attachments.max_file_size_kb', 10240));

        $payload = $request->validate([
            'kind' => ['nullable', Rule::in(['image', 'video', 'audio', 'document', 'other'])],
            'source' => ['nullable', 'string', 'max:64'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'meta' => ['nullable', 'array'],
            'files' => ['required', 'array', 'min:1', 'max:'.$maxFiles],
            'files.*' => ['required', 'file', 'max:'.$maxKb],
        ]);

        $created = [];

        foreach ($request->file('files', []) as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $kind = is_string($payload['kind'] ?? null) && trim((string) $payload['kind']) !== ''
                ? trim((string) $payload['kind'])
                : $this->inferKind($file->getClientMimeType());

            $attachment = $attachmentService->storeUploadedFile($tenant, $file, [
                'entity_type' => self::ENTITY_TYPE,
                'entity_id' => (int) $tenant->id,
                'kind' => $kind,
                'source' => $payload['source'] ?? 'manual',
                'title' => $payload['title'] ?? null,
                'description' => $payload['description'] ?? null,
                'meta' => $payload['meta'] ?? [],
                'uploaded_by' => optional($request->user())->id,
            ]);

            Activity::query()->withoutTenancy()->create([
                'tenant_id' => (int) $tenant->id,
                'actor_id' => optional($request->user())->id,
                'type' => 'media_library.asset.uploaded',
                'subject_type' => Attachment::class,
                'subject_id' => (int) $attachment->id,
                'description' => 'Media library asset uploaded.',
                'properties' => [
                    'kind' => $attachment->kind,
                    'original_name' => $attachment->original_name,
                    'mime_type' => $attachment->mime_type,
                    'size_bytes' => $attachment->size_bytes,
                    'scan_status' => $attachment->scan_status,
                ],
            ]);

            $created[] = $attachment->load('uploader:id,name,email');
        }

        return response()->json([
            'message' => 'Media assets uploaded successfully.',
            'assets' => $created,
        ], 201);
    }

    /**
     * Download one media library asset.
     */
    public function download(Request $request, Attachment $attachment, AttachmentService $attachmentService): StreamedResponse
    {
        $this->authorizePermission($request, 'templates.view');
        $tenant = $this->tenant($request);
        $this->assertMediaAssetScope($tenant, $attachment);

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => (int) $tenant->id,
            'actor_id' => optional($request->user())->id,
            'type' => 'media_library.asset.downloaded',
            'subject_type' => Attachment::class,
            'subject_id' => (int) $attachment->id,
            'description' => 'Media library asset downloaded.',
            'properties' => [
                'kind' => $attachment->kind,
                'original_name' => $attachment->original_name,
            ],
        ]);

        return $attachmentService->downloadResponse($attachment);
    }

    /**
     * Delete one media library asset.
     */
    public function destroy(Request $request, Attachment $attachment, AttachmentService $attachmentService): JsonResponse
    {
        $this->authorizePermission($request, 'templates.update');
        $tenant = $this->tenant($request);
        $this->assertMediaAssetScope($tenant, $attachment);

        $attachmentService->deleteAttachment($attachment);

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => (int) $tenant->id,
            'actor_id' => optional($request->user())->id,
            'type' => 'media_library.asset.deleted',
            'subject_type' => Attachment::class,
            'subject_id' => (int) $attachment->id,
            'description' => 'Media library asset deleted.',
            'properties' => [
                'kind' => $attachment->kind,
                'original_name' => $attachment->original_name,
            ],
        ]);

        return response()->json([
            'message' => 'Media asset deleted successfully.',
        ]);
    }

    private function tenant(Request $request): Tenant
    {
        $tenantId = $this->resolveTenantIdForPermission($request, $request->user());

        if (! is_int($tenantId) || $tenantId <= 0) {
            abort(422, 'Tenant context is required.');
        }

        return Tenant::query()->whereKey($tenantId)->firstOrFail();
    }

    private function assertMediaAssetScope(Tenant $tenant, Attachment $attachment): void
    {
        if ((int) $attachment->tenant_id !== (int) $tenant->id) {
            abort(404, 'Media asset not found in tenant scope.');
        }

        if ((string) $attachment->entity_type !== self::ENTITY_TYPE) {
            abort(404, 'Attachment is not a media library asset.');
        }
    }

    private function inferKind(?string $mimeType): string
    {
        $mime = mb_strtolower(trim((string) $mimeType));

        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }

        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }

        if (str_starts_with($mime, 'audio/')) {
            return 'audio';
        }

        if (str_starts_with($mime, 'application/')) {
            return 'document';
        }

        return 'other';
    }
}
