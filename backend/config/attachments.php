<?php

$allowedMimeTypes = explode(',', (string) env(
    'ATTACHMENTS_ALLOWED_MIME_TYPES',
    implode(',', [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'image/jpeg',
        'image/png',
        'image/webp',
        'text/plain',
        'audio/mpeg',
        'video/mp4',
    ])
));

$allowedMimeTypes = array_values(array_filter(array_map(
    static fn (string $mime): string => trim($mime),
    $allowedMimeTypes
)));

return [
    'disk' => (string) env('ATTACHMENTS_DISK', env('FILESYSTEM_DISK', 'local')),
    'max_file_size_kb' => (int) env('ATTACHMENTS_MAX_FILE_SIZE_KB', 10240),
    'max_files_per_request' => (int) env('ATTACHMENTS_MAX_FILES_PER_REQUEST', 10),
    'allowed_mime_types' => $allowedMimeTypes,
    'retention_days' => (int) env('ATTACHMENTS_RETENTION_DAYS', 365),
    'entity_types' => ['lead', 'deal', 'media_library'],
    'virus_scan' => [
        'enabled' => (bool) env('ATTACHMENTS_VIRUS_SCAN_ENABLED', false),
        'driver' => (string) env('ATTACHMENTS_VIRUS_SCAN_DRIVER', 'none'),
        'fail_closed' => (bool) env('ATTACHMENTS_VIRUS_SCAN_FAIL_CLOSED', false),
        'clamav' => [
            'command' => (string) env('ATTACHMENTS_CLAMAV_COMMAND', 'clamscan --no-summary'),
        ],
    ],
];
