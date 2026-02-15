<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

class AttachmentVirusScanService
{
    /**
     * Scan one uploaded file and return scan metadata.
     *
     * @return array{status: string, engine: ?string, result: ?string}
     */
    public function scan(UploadedFile $file): array
    {
        if (! (bool) config('attachments.virus_scan.enabled', false)) {
            return [
                'status' => 'skipped',
                'engine' => null,
                'result' => null,
            ];
        }

        $driver = mb_strtolower(trim((string) config('attachments.virus_scan.driver', 'none')));

        return match ($driver) {
            'eicar' => $this->scanWithEicarSignature($file),
            'clamav' => $this->scanWithClamav($file),
            default => [
                'status' => 'skipped',
                'engine' => $driver !== '' ? $driver : 'none',
                'result' => 'No scanner driver configured.',
            ],
        };
    }

    /**
     * Lightweight scanner for local/dev using EICAR signature detection.
     *
     * @return array{status: string, engine: ?string, result: ?string}
     */
    private function scanWithEicarSignature(UploadedFile $file): array
    {
        $path = $file->getRealPath();

        if (! is_string($path) || $path === '') {
            return [
                'status' => 'failed',
                'engine' => 'eicar',
                'result' => 'Unable to read uploaded temp file.',
            ];
        }

        $signature = 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE';
        $handle = @fopen($path, 'rb');

        if (! is_resource($handle)) {
            return [
                'status' => 'failed',
                'engine' => 'eicar',
                'result' => 'Unable to open uploaded file for scanning.',
            ];
        }

        $found = false;

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, 8192);

                if (! is_string($chunk) || $chunk === '') {
                    continue;
                }

                if (str_contains($chunk, $signature)) {
                    $found = true;
                    break;
                }
            }
        } finally {
            fclose($handle);
        }

        if ($found) {
            return [
                'status' => 'infected',
                'engine' => 'eicar',
                'result' => 'EICAR signature detected.',
            ];
        }

        return [
            'status' => 'clean',
            'engine' => 'eicar',
            'result' => 'No EICAR signature detected.',
        ];
    }

    /**
     * Scan with local ClamAV command.
     *
     * @return array{status: string, engine: ?string, result: ?string}
     */
    private function scanWithClamav(UploadedFile $file): array
    {
        $path = $file->getRealPath();

        if (! is_string($path) || $path === '') {
            return [
                'status' => 'failed',
                'engine' => 'clamav',
                'result' => 'Unable to read uploaded temp file.',
            ];
        }

        $command = trim((string) config('attachments.virus_scan.clamav.command', 'clamscan --no-summary'));

        if ($command === '') {
            return [
                'status' => 'failed',
                'engine' => 'clamav',
                'result' => 'ClamAV command is not configured.',
            ];
        }

        $output = [];
        $exitCode = 0;

        @exec($command.' '.escapeshellarg($path).' 2>&1', $output, $exitCode);

        $result = trim(implode("\n", array_map(static fn (mixed $line): string => (string) $line, $output)));

        if ($exitCode === 0) {
            return [
                'status' => 'clean',
                'engine' => 'clamav',
                'result' => $result !== '' ? $result : 'Clean.',
            ];
        }

        if ($exitCode === 1) {
            return [
                'status' => 'infected',
                'engine' => 'clamav',
                'result' => $result !== '' ? $result : 'Infected.',
            ];
        }

        return [
            'status' => 'failed',
            'engine' => 'clamav',
            'result' => $result !== '' ? $result : 'ClamAV scan failed.',
        ];
    }
}
