<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentService
{
    public function __construct(
        private readonly AttachmentVirusScanService $virusScanService
    ) {
    }

    /**
     * Store one uploaded file for a tenant entity.
     *
     * @param array<string, mixed> $context
     */
    public function storeUploadedFile(
        Tenant $tenant,
        UploadedFile $file,
        array $context
    ): Attachment {
        $this->validateFile($file);

        $scan = $this->virusScanService->scan($file);
        $scanStatus = mb_strtolower(trim((string) ($scan['status'] ?? 'failed')));
        $scanEngine = is_string($scan['engine'] ?? null) ? (string) $scan['engine'] : null;
        $scanResult = is_string($scan['result'] ?? null) ? (string) $scan['result'] : null;

        if ($scanStatus === 'infected') {
            throw ValidationException::withMessages([
                'files' => ['File was blocked by virus scanning.'],
            ]);
        }

        if ($scanStatus === 'failed' && (bool) config('attachments.virus_scan.fail_closed', false)) {
            throw ValidationException::withMessages([
                'files' => ['Virus scan failed and fail-closed policy is enabled.'],
            ]);
        }

        $entityType = mb_strtolower(trim((string) ($context['entity_type'] ?? 'lead')));
        $entityId = (int) ($context['entity_id'] ?? 0);
        $disk = $this->resolveDisk($tenant);
        $directory = sprintf(
            'attachments/tenants/%d/%s/%d/%s',
            (int) $tenant->id,
            $entityType,
            $entityId,
            now()->format('Y/m')
        );

        $extension = mb_strtolower((string) $file->getClientOriginalExtension());
        $generatedName = Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');
        $path = $directory.'/'.$generatedName;
        $stream = @fopen((string) $file->getRealPath(), 'rb');

        if (! is_resource($stream)) {
            throw ValidationException::withMessages([
                'files' => ['Unable to read uploaded file stream.'],
            ]);
        }

        try {
            $written = Storage::disk($disk)->put($path, $stream, ['visibility' => 'private']);
        } finally {
            fclose($stream);
        }

        if (! $written) {
            throw ValidationException::withMessages([
                'files' => ['Failed to store uploaded file.'],
            ]);
        }

        $realPath = $file->getRealPath();
        $checksum = is_string($realPath) && $realPath !== '' ? hash_file('sha256', $realPath) : null;

        return Attachment::query()->withoutTenancy()->create([
            'tenant_id' => (int) $tenant->id,
            'lead_id' => $entityType === 'lead' ? $entityId : null,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'kind' => (string) ($context['kind'] ?? 'document'),
            'source' => (string) ($context['source'] ?? 'manual'),
            'title' => is_string($context['title'] ?? null) ? trim((string) $context['title']) : null,
            'description' => is_string($context['description'] ?? null) ? trim((string) $context['description']) : null,
            'storage_disk' => $disk,
            'storage_path' => $path,
            'original_name' => (string) $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'extension' => $extension !== '' ? $extension : null,
            'size_bytes' => (int) $file->getSize(),
            'checksum_sha256' => is_string($checksum) ? $checksum : null,
            'visibility' => 'private',
            'scan_status' => $scanStatus !== '' ? $scanStatus : 'failed',
            'scanned_at' => now(),
            'scan_engine' => $scanEngine,
            'scan_result' => $scanResult,
            'uploaded_by' => is_numeric($context['uploaded_by'] ?? null) ? (int) $context['uploaded_by'] : null,
            'meta' => is_array($context['meta'] ?? null) ? $context['meta'] : [],
            'expires_at' => $this->resolveExpiration($tenant),
        ]);
    }

    /**
     * Stream one attachment download.
     */
    public function downloadResponse(Attachment $attachment): StreamedResponse
    {
        if (! Storage::disk($attachment->storage_disk)->exists($attachment->storage_path)) {
            abort(404, 'Attachment file is missing from storage.');
        }

        return Storage::disk($attachment->storage_disk)->download(
            $attachment->storage_path,
            $attachment->original_name,
            array_filter([
                'Content-Type' => $attachment->mime_type,
            ])
        );
    }

    /**
     * Remove one attachment row and file.
     */
    public function deleteAttachment(Attachment $attachment, bool $force = false): void
    {
        if (
            is_string($attachment->storage_disk)
            && $attachment->storage_disk !== ''
            && is_string($attachment->storage_path)
            && $attachment->storage_path !== ''
            && Storage::disk($attachment->storage_disk)->exists($attachment->storage_path)
        ) {
            Storage::disk($attachment->storage_disk)->delete($attachment->storage_path);
        }

        if ($force) {
            $attachment->forceDelete();

            return;
        }

        $attachment->delete();
    }

    /**
     * Validate file constraints from attachments config.
     */
    private function validateFile(UploadedFile $file): void
    {
        $maxBytes = max(1, (int) config('attachments.max_file_size_kb', 10240)) * 1024;
        $size = (int) $file->getSize();

        if ($size <= 0 || $size > $maxBytes) {
            throw ValidationException::withMessages([
                'files' => [sprintf(
                    'File size exceeds maximum allowed (%d KB).',
                    (int) config('attachments.max_file_size_kb', 10240)
                )],
            ]);
        }

        $allowedMimeTypes = config('attachments.allowed_mime_types', []);

        if (! is_array($allowedMimeTypes) || $allowedMimeTypes === []) {
            return;
        }

        $mime = mb_strtolower(trim((string) $file->getClientMimeType()));

        if ($mime === '') {
            throw ValidationException::withMessages([
                'files' => ['Unable to determine file MIME type.'],
            ]);
        }

        $allowed = collect($allowedMimeTypes)
            ->map(static fn (mixed $value): string => mb_strtolower(trim((string) $value)))
            ->filter()
            ->values();

        $exactMatch = $allowed->contains($mime);
        $wildcardMatch = $allowed->contains(function (string $pattern) use ($mime): bool {
            if (! str_contains($pattern, '/*')) {
                return false;
            }

            $prefix = rtrim(substr($pattern, 0, -1), '/');

            return $prefix !== '' && str_starts_with($mime, $prefix.'/');
        });

        if (! $exactMatch && ! $wildcardMatch) {
            throw ValidationException::withMessages([
                'files' => [sprintf('File type "%s" is not allowed.', $mime)],
            ]);
        }
    }

    /**
     * Resolve storage disk from tenant settings with config fallback.
     */
    private function resolveDisk(Tenant $tenant): string
    {
        $tenantDisk = data_get($tenant->settings, 'attachments.disk');
        $configuredDisk = is_string($tenantDisk) ? trim($tenantDisk) : '';

        if ($configuredDisk !== '' && config('filesystems.disks.'.$configuredDisk) !== null) {
            return $configuredDisk;
        }

        $defaultDisk = trim((string) config('attachments.disk', 'local'));

        if ($defaultDisk !== '' && config('filesystems.disks.'.$defaultDisk) !== null) {
            return $defaultDisk;
        }

        return (string) config('filesystems.default', 'local');
    }

    /**
     * Resolve attachment expiration from tenant/global retention settings.
     */
    private function resolveExpiration(Tenant $tenant): ?Carbon
    {
        $tenantDays = data_get($tenant->settings, 'retention.attachments_days');
        $days = is_numeric($tenantDays)
            ? (int) $tenantDays
            : (int) config('attachments.retention_days', 365);

        if ($days <= 0) {
            return null;
        }

        return now()->addDays($days);
    }
}
