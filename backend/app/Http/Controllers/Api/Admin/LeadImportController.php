<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\LeadImportPreset;
use App\Models\LeadImportSchedule;
use App\Services\LeadImportService;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LeadImportController extends Controller
{
    /**
     * List mapping presets for the active tenant.
     */
    public function presetsIndex(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'leads.view');
        $tenantId = $this->tenantId($request);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = LeadImportPreset::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id');

        if (is_string($filters['search'] ?? null) && trim((string) $filters['search']) !== '') {
            $search = trim((string) $filters['search']);

            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        return response()->json([
            'presets' => $query->paginate((int) ($filters['per_page'] ?? 15))->withQueryString(),
        ]);
    }

    /**
     * Create one mapping preset.
     */
    public function presetsStore(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'leads.update');
        $tenantId = $this->tenantId($request);
        $payload = $this->validatePresetPayload($request, $tenantId);
        $actorId = optional($request->user())->id;

        $preset = LeadImportPreset::query()->withoutTenancy()->create([
            'tenant_id' => $tenantId,
            'name' => $payload['name'],
            'slug' => $payload['slug'] ?? Str::slug($payload['name']),
            'description' => $payload['description'] ?? null,
            'mapping' => $payload['mapping'] ?? [],
            'defaults' => $payload['defaults'] ?? [],
            'dedupe_policy' => $payload['dedupe_policy'] ?? 'skip',
            'dedupe_keys' => $payload['dedupe_keys'] ?? ['email', 'phone'],
            'settings' => $payload['settings'] ?? [],
            'is_active' => $payload['is_active'] ?? true,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'type' => 'lead.import.preset.created',
            'subject_type' => LeadImportPreset::class,
            'subject_id' => (int) $preset->id,
            'description' => 'Lead import preset created.',
        ]);

        return response()->json([
            'message' => 'Import preset created successfully.',
            'preset' => $preset,
        ], 201);
    }

    /**
     * Show one mapping preset.
     */
    public function presetsShow(Request $request, LeadImportPreset $leadImportPreset): JsonResponse
    {
        $this->authorizePermission($request, 'leads.view');
        $tenantId = $this->tenantId($request);
        $this->assertPresetScope($leadImportPreset, $tenantId);

        return response()->json([
            'preset' => $leadImportPreset,
        ]);
    }

    /**
     * Update one mapping preset.
     */
    public function presetsUpdate(Request $request, LeadImportPreset $leadImportPreset): JsonResponse
    {
        $this->authorizePermission($request, 'leads.update');
        $tenantId = $this->tenantId($request);
        $this->assertPresetScope($leadImportPreset, $tenantId);
        $payload = $this->validatePresetPayload($request, $tenantId, true, (int) $leadImportPreset->id);

        $leadImportPreset->fill([
            'name' => $payload['name'] ?? $leadImportPreset->name,
            'slug' => $payload['slug'] ?? $leadImportPreset->slug,
            'description' => array_key_exists('description', $payload)
                ? $payload['description']
                : $leadImportPreset->description,
            'mapping' => array_key_exists('mapping', $payload)
                ? ($payload['mapping'] ?? [])
                : $leadImportPreset->mapping,
            'defaults' => array_key_exists('defaults', $payload)
                ? ($payload['defaults'] ?? [])
                : $leadImportPreset->defaults,
            'dedupe_policy' => $payload['dedupe_policy'] ?? $leadImportPreset->dedupe_policy,
            'dedupe_keys' => array_key_exists('dedupe_keys', $payload)
                ? ($payload['dedupe_keys'] ?? ['email', 'phone'])
                : $leadImportPreset->dedupe_keys,
            'settings' => array_key_exists('settings', $payload)
                ? ($payload['settings'] ?? [])
                : $leadImportPreset->settings,
            'is_active' => $payload['is_active'] ?? $leadImportPreset->is_active,
            'updated_by' => optional($request->user())->id,
        ])->save();

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => $tenantId,
            'actor_id' => optional($request->user())->id,
            'type' => 'lead.import.preset.updated',
            'subject_type' => LeadImportPreset::class,
            'subject_id' => (int) $leadImportPreset->id,
            'description' => 'Lead import preset updated.',
        ]);

        return response()->json([
            'message' => 'Import preset updated successfully.',
            'preset' => $leadImportPreset->refresh(),
        ]);
    }

    /**
     * Delete one mapping preset.
     */
    public function presetsDestroy(Request $request, LeadImportPreset $leadImportPreset): JsonResponse
    {
        $this->authorizePermission($request, 'leads.update');
        $tenantId = $this->tenantId($request);
        $this->assertPresetScope($leadImportPreset, $tenantId);

        $leadImportPreset->delete();

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => $tenantId,
            'actor_id' => optional($request->user())->id,
            'type' => 'lead.import.preset.deleted',
            'subject_type' => LeadImportPreset::class,
            'subject_id' => (int) $leadImportPreset->id,
            'description' => 'Lead import preset deleted.',
        ]);

        return response()->json([
            'message' => 'Import preset deleted successfully.',
        ]);
    }

    /**
     * List scheduled import jobs.
     */
    public function schedulesIndex(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'leads.view');
        $tenantId = $this->tenantId($request);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'is_active' => ['nullable', 'boolean'],
            'source_type' => ['nullable', Rule::in(['url', 'sftp'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = LeadImportSchedule::query()
            ->withoutTenancy()
            ->with('preset:id,tenant_id,name,slug')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id');

        if (is_string($filters['search'] ?? null) && trim((string) $filters['search']) !== '') {
            $search = trim((string) $filters['search']);

            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('source_type', 'like', "%{$search}%")
                    ->orWhere('schedule_cron', 'like', "%{$search}%");
            });
        }

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (is_string($filters['source_type'] ?? null) && trim((string) $filters['source_type']) !== '') {
            $query->where('source_type', trim((string) $filters['source_type']));
        }

        return response()->json([
            'schedules' => $query->paginate((int) ($filters['per_page'] ?? 15))->withQueryString(),
        ]);
    }

    /**
     * Create one scheduled import job.
     */
    public function schedulesStore(Request $request, LeadImportService $leadImportService): JsonResponse
    {
        $this->authorizePermission($request, 'leads.update');
        $tenantId = $this->tenantId($request);
        $payload = $this->validateSchedulePayload($request, $tenantId, null);
        $nextRunAt = (bool) ($payload['is_active'] ?? true)
            ? $leadImportService->nextRunAt(
                (string) $payload['schedule_cron'],
                $this->resolveScheduleTimezone($payload, null)
            )
            : null;
        $actorId = optional($request->user())->id;

        $schedule = LeadImportSchedule::query()->withoutTenancy()->create([
            'tenant_id' => $tenantId,
            'preset_id' => $payload['preset_id'] ?? null,
            'name' => $payload['name'],
            'source_type' => $payload['source_type'],
            'source_config' => $payload['source_config'],
            'mapping' => $payload['mapping'] ?? [],
            'defaults' => $payload['defaults'] ?? [],
            'dedupe_policy' => $payload['dedupe_policy'] ?? 'skip',
            'dedupe_keys' => $payload['dedupe_keys'] ?? ['email', 'phone'],
            'auto_assign' => array_key_exists('auto_assign', $payload) ? (bool) $payload['auto_assign'] : true,
            'schedule_cron' => $payload['schedule_cron'],
            'timezone' => $this->resolveScheduleTimezone($payload, null),
            'is_active' => $payload['is_active'] ?? true,
            'next_run_at' => $nextRunAt,
            'settings' => $payload['settings'] ?? [],
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'type' => 'lead.import.schedule.created',
            'subject_type' => LeadImportSchedule::class,
            'subject_id' => (int) $schedule->id,
            'description' => 'Lead import schedule created.',
        ]);

        return response()->json([
            'message' => 'Import schedule created successfully.',
            'schedule' => $schedule->load('preset:id,tenant_id,name,slug'),
        ], 201);
    }

    /**
     * Show one scheduled import job.
     */
    public function schedulesShow(Request $request, LeadImportSchedule $leadImportSchedule): JsonResponse
    {
        $this->authorizePermission($request, 'leads.view');
        $tenantId = $this->tenantId($request);
        $this->assertScheduleScope($leadImportSchedule, $tenantId);

        return response()->json([
            'schedule' => $leadImportSchedule->load('preset:id,tenant_id,name,slug'),
        ]);
    }

    /**
     * Update one scheduled import job.
     */
    public function schedulesUpdate(
        Request $request,
        LeadImportSchedule $leadImportSchedule,
        LeadImportService $leadImportService
    ): JsonResponse {
        $this->authorizePermission($request, 'leads.update');
        $tenantId = $this->tenantId($request);
        $this->assertScheduleScope($leadImportSchedule, $tenantId);
        $payload = $this->validateSchedulePayload($request, $tenantId, $leadImportSchedule);

        $isActive = $payload['is_active'] ?? $leadImportSchedule->is_active;
        $scheduleCron = $payload['schedule_cron'] ?? $leadImportSchedule->schedule_cron;
        $timezone = $this->resolveScheduleTimezone($payload, $leadImportSchedule);
        $nextRunAt = $isActive
            ? $leadImportService->nextRunAt((string) $scheduleCron, $timezone)
            : null;

        $leadImportSchedule->fill([
            'preset_id' => array_key_exists('preset_id', $payload)
                ? $payload['preset_id']
                : $leadImportSchedule->preset_id,
            'name' => $payload['name'] ?? $leadImportSchedule->name,
            'source_type' => $payload['source_type'] ?? $leadImportSchedule->source_type,
            'source_config' => array_key_exists('source_config', $payload)
                ? $payload['source_config']
                : $leadImportSchedule->source_config,
            'mapping' => array_key_exists('mapping', $payload)
                ? ($payload['mapping'] ?? [])
                : $leadImportSchedule->mapping,
            'defaults' => array_key_exists('defaults', $payload)
                ? ($payload['defaults'] ?? [])
                : $leadImportSchedule->defaults,
            'dedupe_policy' => $payload['dedupe_policy'] ?? $leadImportSchedule->dedupe_policy,
            'dedupe_keys' => array_key_exists('dedupe_keys', $payload)
                ? ($payload['dedupe_keys'] ?? ['email', 'phone'])
                : $leadImportSchedule->dedupe_keys,
            'auto_assign' => array_key_exists('auto_assign', $payload)
                ? (bool) $payload['auto_assign']
                : $leadImportSchedule->auto_assign,
            'schedule_cron' => $scheduleCron,
            'timezone' => $timezone,
            'is_active' => $isActive,
            'next_run_at' => $nextRunAt,
            'settings' => array_key_exists('settings', $payload)
                ? ($payload['settings'] ?? [])
                : $leadImportSchedule->settings,
            'updated_by' => optional($request->user())->id,
        ])->save();

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => $tenantId,
            'actor_id' => optional($request->user())->id,
            'type' => 'lead.import.schedule.updated',
            'subject_type' => LeadImportSchedule::class,
            'subject_id' => (int) $leadImportSchedule->id,
            'description' => 'Lead import schedule updated.',
        ]);

        return response()->json([
            'message' => 'Import schedule updated successfully.',
            'schedule' => $leadImportSchedule->refresh()->load('preset:id,tenant_id,name,slug'),
        ]);
    }

    /**
     * Delete one scheduled import job.
     */
    public function schedulesDestroy(Request $request, LeadImportSchedule $leadImportSchedule): JsonResponse
    {
        $this->authorizePermission($request, 'leads.update');
        $tenantId = $this->tenantId($request);
        $this->assertScheduleScope($leadImportSchedule, $tenantId);

        $leadImportSchedule->delete();

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => $tenantId,
            'actor_id' => optional($request->user())->id,
            'type' => 'lead.import.schedule.deleted',
            'subject_type' => LeadImportSchedule::class,
            'subject_id' => (int) $leadImportSchedule->id,
            'description' => 'Lead import schedule deleted.',
        ]);

        return response()->json([
            'message' => 'Import schedule deleted successfully.',
        ]);
    }

    /**
     * Execute one schedule immediately.
     */
    public function schedulesRunNow(
        Request $request,
        LeadImportSchedule $leadImportSchedule,
        LeadImportService $leadImportService
    ): JsonResponse {
        $this->authorizePermission($request, 'leads.update');
        $tenantId = $this->tenantId($request);
        $this->assertScheduleScope($leadImportSchedule, $tenantId);

        $result = $leadImportService->runSchedule($leadImportSchedule->load('preset'));

        return response()->json([
            'message' => $result['status'] === 'success'
                ? 'Import schedule executed successfully.'
                : 'Import schedule execution failed.',
            'result' => $result,
        ], $result['status'] === 'success' ? 200 : 422);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePresetPayload(
        Request $request,
        int $tenantId,
        bool $isUpdate = false,
        ?int $ignoreId = null
    ): array {
        $slugRule = Rule::unique('lead_import_presets', 'slug')
            ->where(fn ($query) => $query->where('tenant_id', $tenantId));

        if ($ignoreId !== null) {
            $slugRule->ignore($ignoreId);
        }

        return $request->validate([
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:150'],
            'slug' => ['sometimes', 'string', 'max:180', $slugRule],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'mapping' => ['sometimes', 'nullable', 'array'],
            'defaults' => ['sometimes', 'nullable', 'array'],
            'dedupe_policy' => ['sometimes', Rule::in(['skip', 'update', 'merge'])],
            'dedupe_keys' => ['sometimes', 'array'],
            'dedupe_keys.*' => [Rule::in(['email', 'phone'])],
            'settings' => ['sometimes', 'nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateSchedulePayload(
        Request $request,
        int $tenantId,
        ?LeadImportSchedule $existing
    ): array {
        $payload = $request->validate([
            'preset_id' => ['sometimes', 'nullable', 'integer', 'exists:lead_import_presets,id'],
            'name' => [$existing === null ? 'required' : 'sometimes', 'string', 'max:150'],
            'source_type' => ['sometimes', Rule::in(['url', 'sftp'])],
            'source_config' => ['sometimes', 'array'],
            'mapping' => ['sometimes', 'nullable', 'array'],
            'defaults' => ['sometimes', 'nullable', 'array'],
            'dedupe_policy' => ['sometimes', Rule::in(['skip', 'update', 'merge'])],
            'dedupe_keys' => ['sometimes', 'array'],
            'dedupe_keys.*' => [Rule::in(['email', 'phone'])],
            'auto_assign' => ['sometimes', 'boolean'],
            'schedule_cron' => [$existing === null ? 'required' : 'sometimes', 'string', 'max:120'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'is_active' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'nullable', 'array'],
        ]);

        $presetId = array_key_exists('preset_id', $payload)
            ? $payload['preset_id']
            : $existing?->preset_id;

        if (is_numeric($presetId)) {
            $presetExists = LeadImportPreset::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->whereKey((int) $presetId)
                ->exists();

            if (! $presetExists) {
                abort(422, 'Provided preset_id does not belong to the active tenant.');
            }
        }

        $sourceType = (string) ($payload['source_type'] ?? $existing?->source_type ?? '');
        $sourceConfig = is_array($payload['source_config'] ?? null)
            ? $payload['source_config']
            : (is_array($existing?->source_config) ? $existing->source_config : []);

        if ($existing === null && $sourceType === '') {
            abort(422, 'source_type is required.');
        }

        if ($existing === null && $sourceConfig === []) {
            abort(422, 'source_config is required.');
        }

        if ($sourceType === 'url') {
            $url = trim((string) ($sourceConfig['url'] ?? ''));

            if ($url === '') {
                abort(422, 'source_config.url is required when source_type=url.');
            }

            $this->assertSafeImportUrl($url);
        } elseif ($sourceType === 'sftp') {
            $host = trim((string) ($sourceConfig['host'] ?? ''));
            $username = trim((string) ($sourceConfig['username'] ?? ''));
            $path = trim((string) ($sourceConfig['path'] ?? ''));

            if ($host === '' || $username === '' || $path === '') {
                abort(
                    422,
                    'source_config.host, source_config.username, and source_config.path are required when source_type=sftp.'
                );
            }
        }

        $cron = (string) ($payload['schedule_cron'] ?? $existing?->schedule_cron ?? '');

        if ($cron === '' || ! CronExpression::isValidExpression($cron)) {
            abort(422, 'schedule_cron is invalid.');
        }

        return $payload;
    }

    private function tenantId(Request $request): int
    {
        $tenantId = $this->resolveTenantIdForPermission($request, $request->user());

        if (! is_int($tenantId) || $tenantId <= 0) {
            abort(422, 'Tenant context is required.');
        }

        return $tenantId;
    }

    private function assertPresetScope(LeadImportPreset $preset, int $tenantId): void
    {
        if ((int) $preset->tenant_id !== $tenantId) {
            abort(404, 'Import preset not found in tenant scope.');
        }
    }

    private function assertScheduleScope(LeadImportSchedule $schedule, int $tenantId): void
    {
        if ((int) $schedule->tenant_id !== $tenantId) {
            abort(404, 'Import schedule not found in tenant scope.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveScheduleTimezone(array $payload, ?LeadImportSchedule $existing): string
    {
        $timezone = trim((string) ($payload['timezone'] ?? $existing?->timezone ?? ''));

        return $timezone !== '' ? $timezone : 'UTC';
    }

    private function assertSafeImportUrl(string $url): void
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            abort(422, 'source_config.url must be a valid URL.');
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        $embeddedUser = trim((string) ($parts['user'] ?? ''));
        $embeddedPass = trim((string) ($parts['pass'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true)) {
            abort(422, 'source_config.url must use http or https.');
        }

        if ($host === '') {
            abort(422, 'source_config.url must include a host.');
        }

        if ($embeddedUser !== '' || $embeddedPass !== '') {
            abort(422, 'source_config.url must not include embedded credentials.');
        }

        if (
            in_array($host, ['localhost', '::1'], true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
        ) {
            abort(422, 'source_config.url must target a public host.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $isPublicIp = filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false;

            if (! $isPublicIp) {
                abort(422, 'source_config.url must target a public host.');
            }
        }
    }
}
