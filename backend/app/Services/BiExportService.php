<?php

namespace App\Services;

use App\Models\ExportJob;
use App\Models\Lead;
use App\Models\Message;
use App\Models\RealtimeEvent;
use Illuminate\Support\Facades\Storage;

class BiExportService
{
    /**
     * Estimate row count for one export type before execution.
     */
    public function estimateRowCount(int $tenantId, string $type): int
    {
        $type = $this->normalizeType($type);

        if ($type === 'messages') {
            return Message::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->count();
        }

        if ($type === 'events') {
            return RealtimeEvent::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->count();
        }

        return Lead::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->count();
    }

    /**
     * Export tenant dataset to CSV file path under local storage.
     */
    public function exportToCsv(int $tenantId, string $type): ExportJob
    {
        $type = $this->normalizeType($type);
        $job = ExportJob::query()->withoutTenancy()->create([
            'tenant_id' => $tenantId,
            'type' => $type,
            'destination' => 'download',
            'status' => 'running',
            'payload' => ['started_at' => now()->toIso8601String()],
        ]);

        $rows = $this->rows($tenantId, $type);
        $headers = array_keys($rows[0] ?? ['id' => null]);
        $csv = $this->toCsv($headers, $rows);
        $path = "exports/tenant-{$tenantId}/{$type}-{$job->id}.csv";

        Storage::disk('local')->put($path, $csv);

        $job->forceFill([
            'status' => 'completed',
            'file_path' => $path,
            'completed_at' => now(),
        ])->save();

        return $job->refresh();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(int $tenantId, string $type): array
    {
        $type = $this->normalizeType($type);

        if ($type === 'messages') {
            return Message::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->limit(10000)
                ->get(['id', 'lead_id', 'channel', 'direction', 'status', 'provider', 'created_at'])
                ->map(fn ($row): array => $row->toArray())
                ->all();
        }

        if ($type === 'events') {
            return RealtimeEvent::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->limit(10000)
                ->get(['id', 'event_name', 'subject_type', 'subject_id', 'occurred_at'])
                ->map(fn ($row): array => $row->toArray())
                ->all();
        }

        return Lead::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->limit(10000)
            ->get(['id', 'first_name', 'last_name', 'email', 'phone', 'status', 'source', 'created_at'])
            ->map(fn ($row): array => $row->toArray())
            ->all();
    }

    /**
     * @param list<string> $headers
     * @param list<array<string, mixed>> $rows
     */
    private function toCsv(array $headers, array $rows): string
    {
        $stream = fopen('php://temp', 'w+');

        if ($stream === false) {
            return '';
        }

        fputcsv($stream, $headers);

        foreach ($rows as $row) {
            $line = [];

            foreach ($headers as $header) {
                $value = $row[$header] ?? null;

                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }

                $line[] = $value;
            }

            fputcsv($stream, $line);
        }

        rewind($stream);
        $csv = stream_get_contents($stream) ?: '';
        fclose($stream);

        return $csv;
    }

    private function normalizeType(string $type): string
    {
        return mb_strtolower(trim($type));
    }
}
