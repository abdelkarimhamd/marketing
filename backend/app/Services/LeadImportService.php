<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Lead;
use App\Models\LeadImportSchedule;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use Cron\CronExpression;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

class LeadImportService
{
    /**
     * @var list<string>
     */
    private const DEDUPE_POLICIES = ['skip', 'update', 'merge'];

    /**
     * @var list<string>
     */
    private const DEDUPE_KEYS = ['email', 'phone'];

    /**
     * @var list<string>
     */
    private const LEAD_FIELDS = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'company',
        'city',
        'country_code',
        'interest',
        'service',
        'title',
        'status',
        'source',
        'score',
        'locale',
        'team_id',
        'owner_id',
        'email_consent',
        'meta',
        'settings',
        'tags',
        'custom_fields',
    ];

    public function __construct(
        private readonly LeadAssignmentService $assignmentService,
        private readonly LeadEnrichmentService $leadEnrichmentService,
        private readonly CustomFieldService $customFieldService,
        private readonly RealtimeEventService $eventService,
    ) {
    }

    /**
     * Import one batch of rows with mapping + dedupe behavior.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function importRows(int $tenantId, array $rows, array $options = []): array
    {
        $dedupePolicy = $this->normalizeDedupePolicy($options['dedupe_policy'] ?? 'skip');
        $dedupeKeys = $this->normalizeDedupeKeys($options['dedupe_keys'] ?? ['email', 'phone']);
        $mapping = is_array($options['mapping'] ?? null) ? $options['mapping'] : [];
        $defaults = is_array($options['defaults'] ?? null) ? $options['defaults'] : [];
        $autoAssign = array_key_exists('auto_assign', $options) ? (bool) $options['auto_assign'] : true;
        $actorId = is_numeric($options['actor_id'] ?? null) ? (int) $options['actor_id'] : null;
        $source = is_string($options['source'] ?? null) ? trim((string) $options['source']) : 'import';
        $source = $source !== '' ? $source : 'import';
        $importMeta = is_array($options['import_meta'] ?? null) ? $options['import_meta'] : [];

        $result = [
            'created_count' => 0,
            'updated_count' => 0,
            'merged_count' => 0,
            'skipped_count' => 0,
            'assigned_count' => 0,
            'lead_ids' => [],
            'affected_lead_ids' => [],
            'dedupe_policy' => $dedupePolicy,
            'dedupe_keys' => $dedupeKeys,
        ];

        DB::transaction(function () use (
            $tenantId,
            $rows,
            $mapping,
            $defaults,
            $dedupePolicy,
            $dedupeKeys,
            $autoAssign,
            $actorId,
            $source,
            $importMeta,
            &$result
        ): void {
            foreach ($rows as $rawRow) {
                if (! is_array($rawRow)) {
                    continue;
                }

                $mappedRow = $this->applyMapping($rawRow, $mapping);
                $rowWithDefaults = $this->applyDefaults($mappedRow, $defaults);
                $row = $this->normalizeLeadRow($rowWithDefaults);
                $this->validateLeadRow($row);
                $this->validateTenantReferences($tenantId, $row);

                $row = $this->leadEnrichmentService->enrich($row);

                if (empty($row['email']) && empty($row['phone'])) {
                    abort(422, 'Each imported lead must include email or phone.');
                }

                $row['source'] = $row['source'] ?? $source;
                $duplicate = $this->findDuplicateLead($tenantId, $row, $dedupeKeys);

                if ($duplicate instanceof Lead) {
                    if ($dedupePolicy === 'skip') {
                        $result['skipped_count']++;

                        Activity::query()->withoutTenancy()->create([
                            'tenant_id' => $tenantId,
                            'actor_id' => $actorId,
                            'type' => 'lead.import.duplicate.skipped',
                            'subject_type' => Lead::class,
                            'subject_id' => (int) $duplicate->id,
                            'description' => 'Duplicate lead skipped during import.',
                            'properties' => [
                                'source' => $source,
                                'dedupe_keys' => $dedupeKeys,
                            ] + $importMeta,
                        ]);

                        continue;
                    }

                    $ownerBefore = $duplicate->owner_id;
                    $updated = $dedupePolicy === 'update'
                        ? $this->applyUpdatePolicy($duplicate, $row, $tenantId)
                        : $this->applyMergePolicy($duplicate, $row, $tenantId);

                    if ($autoAssign) {
                        $assignee = $this->assignmentService->assignLead($updated, 'import');
                        $updated->refresh();

                        if ($ownerBefore === null && $updated->owner_id !== null && $assignee !== null) {
                            $result['assigned_count']++;
                        }
                    }

                    if ($dedupePolicy === 'update') {
                        $result['updated_count']++;
                    } else {
                        $result['merged_count']++;
                    }

                    $result['affected_lead_ids'][] = (int) $updated->id;
                    $this->emitLeadUpdatedEvent($updated, $source, $importMeta, $dedupePolicy);

                    continue;
                }

                $lead = $this->createLeadFromImportRow($tenantId, $row, $actorId, $source, $importMeta);

                $ownerBefore = $lead->owner_id;

                if ($autoAssign) {
                    $assignee = $this->assignmentService->assignLead($lead, 'import');
                    $lead->refresh();

                    if ($ownerBefore === null && $lead->owner_id !== null && $assignee !== null) {
                        $result['assigned_count']++;
                    }
                }

                $this->emitLeadCreatedEvent($lead, $source, $importMeta);

                $result['created_count']++;
                $result['lead_ids'][] = (int) $lead->id;
                $result['affected_lead_ids'][] = (int) $lead->id;
            }
        });

        $result['lead_ids'] = array_values(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            $result['lead_ids']
        )));
        $result['affected_lead_ids'] = array_values(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            $result['affected_lead_ids']
        )));

        return $result;
    }

    /**
     * Run one saved schedule and persist run status.
     *
     * @return array<string, mixed>
     */
    public function runSchedule(LeadImportSchedule $schedule): array
    {
        $timezone = $this->resolveScheduleTimezone($schedule);
        $nextRunAt = $this->nextRunAt((string) $schedule->schedule_cron, $timezone);

        try {
            $rows = $this->fetchSourceRows($schedule);
            $options = $this->buildScheduleImportOptions($schedule);
            $result = $this->importRows((int) $schedule->tenant_id, $rows, $options);

            $schedule->forceFill([
                'last_status' => 'success',
                'last_error' => null,
                'last_run_at' => now(),
                'next_run_at' => $schedule->is_active ? $nextRunAt : null,
                'last_processed_count' => (int) (
                    ($result['created_count'] ?? 0)
                    + ($result['updated_count'] ?? 0)
                    + ($result['merged_count'] ?? 0)
                ),
                'updated_by' => $schedule->updated_by,
            ])->save();

            Activity::query()->withoutTenancy()->create([
                'tenant_id' => (int) $schedule->tenant_id,
                'actor_id' => $schedule->updated_by,
                'type' => 'lead.import.schedule.ran',
                'subject_type' => LeadImportSchedule::class,
                'subject_id' => (int) $schedule->id,
                'description' => 'Lead import schedule completed successfully.',
                'properties' => [
                    'created_count' => (int) ($result['created_count'] ?? 0),
                    'updated_count' => (int) ($result['updated_count'] ?? 0),
                    'merged_count' => (int) ($result['merged_count'] ?? 0),
                    'skipped_count' => (int) ($result['skipped_count'] ?? 0),
                ],
            ]);

            return [
                'status' => 'success',
                'schedule_id' => (int) $schedule->id,
                'next_run_at' => $schedule->next_run_at?->toIso8601String(),
            ] + $result;
        } catch (Throwable $exception) {
            $schedule->forceFill([
                'last_status' => 'failed',
                'last_error' => $exception->getMessage(),
                'last_run_at' => now(),
                'next_run_at' => $schedule->is_active ? $nextRunAt : null,
                'updated_by' => $schedule->updated_by,
            ])->save();

            Activity::query()->withoutTenancy()->create([
                'tenant_id' => (int) $schedule->tenant_id,
                'actor_id' => $schedule->updated_by,
                'type' => 'lead.import.schedule.failed',
                'subject_type' => LeadImportSchedule::class,
                'subject_id' => (int) $schedule->id,
                'description' => 'Lead import schedule failed.',
                'properties' => [
                    'error' => $exception->getMessage(),
                ],
            ]);

            return [
                'status' => 'failed',
                'schedule_id' => (int) $schedule->id,
                'error' => $exception->getMessage(),
                'next_run_at' => $schedule->next_run_at?->toIso8601String(),
            ];
        }
    }

    public function nextRunAt(string $cronExpression, string $timezone): Carbon
    {
        if (! CronExpression::isValidExpression($cronExpression)) {
            throw new RuntimeException('Invalid schedule_cron expression.');
        }

        $cron = CronExpression::factory($cronExpression);
        $next = $cron->getNextRunDate('now', 0, false, $timezone);

        return Carbon::instance($next)->setTimezone('UTC');
    }

    /**
     * @param array<string, mixed> $rawRow
     * @param array<string, mixed> $mapping
     * @return array<string, mixed>
     */
    private function applyMapping(array $rawRow, array $mapping): array
    {
        if ($mapping === []) {
            return $rawRow;
        }

        $result = $rawRow;

        if (Arr::isAssoc($mapping)) {
            foreach ($mapping as $target => $source) {
                if (! is_string($target) || ! is_string($source)) {
                    continue;
                }

                if (! Arr::has($rawRow, $source)) {
                    continue;
                }

                $value = Arr::get($rawRow, $source);
                data_set($result, $target, $value);
            }

            return $result;
        }

        foreach ($mapping as $row) {
            if (! is_array($row)) {
                continue;
            }

            $source = is_string($row['source'] ?? null) ? (string) $row['source'] : null;
            $target = is_string($row['target'] ?? null) ? (string) $row['target'] : null;

            if ($source === null || $target === null || $source === '' || $target === '') {
                continue;
            }

            if (! Arr::has($rawRow, $source)) {
                continue;
            }

            $value = Arr::get($rawRow, $source);
            data_set($result, $target, $value);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    private function applyDefaults(array $row, array $defaults): array
    {
        if ($defaults === []) {
            return $row;
        }

        foreach ($defaults as $key => $value) {
            if (! is_string($key) || trim($key) === '') {
                continue;
            }

            $current = Arr::get($row, $key);

            if ($this->isBlankValue($current)) {
                data_set($row, $key, $value);
            }
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeLeadRow(array $row): array
    {
        $normalized = [];

        foreach (self::LEAD_FIELDS as $field) {
            if (! Arr::has($row, $field)) {
                continue;
            }

            $value = Arr::get($row, $field);

            if (in_array($field, ['team_id', 'owner_id', 'score'], true) && is_numeric($value)) {
                $normalized[$field] = (int) $value;
                continue;
            }

            if ($field === 'email_consent') {
                $normalized[$field] = (bool) $value;
                continue;
            }

            if (in_array($field, ['meta', 'settings', 'custom_fields'], true)) {
                $normalized[$field] = is_array($value) ? $value : [];
                continue;
            }

            if ($field === 'tags') {
                $normalized[$field] = is_array($value)
                    ? array_values(array_filter(array_map(
                        static fn (mixed $item): string => trim((string) $item),
                        $value
                    )))
                    : [];
                continue;
            }

            $normalized[$field] = is_string($value) ? trim($value) : $value;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function validateLeadRow(array $row): void
    {
        Validator::validate($row, [
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email:rfc', 'max:255', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:32', 'required_without:email'],
            'company' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:150'],
            'country_code' => ['nullable', 'string', 'max:8'],
            'interest' => ['nullable', 'string', 'max:150'],
            'service' => ['nullable', 'string', 'max:150'],
            'title' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:50'],
            'source' => ['nullable', 'string', 'max:100'],
            'score' => ['nullable', 'integer', 'min:0'],
            'locale' => ['nullable', 'string', 'max:12'],
            'team_id' => ['nullable', 'integer', 'min:1'],
            'owner_id' => ['nullable', 'integer', 'min:1'],
            'email_consent' => ['nullable', 'boolean'],
            'meta' => ['nullable', 'array'],
            'settings' => ['nullable', 'array'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:80'],
            'custom_fields' => ['nullable', 'array'],
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function validateTenantReferences(int $tenantId, array $row): void
    {
        if (array_key_exists('team_id', $row) && $row['team_id'] !== null) {
            $teamExists = Team::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->whereKey((int) $row['team_id'])
                ->exists();

            if (! $teamExists) {
                abort(422, 'Provided team_id does not belong to the active tenant.');
            }
        }

        if (array_key_exists('owner_id', $row) && $row['owner_id'] !== null) {
            $ownerExists = User::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->whereKey((int) $row['owner_id'])
                ->exists();

            if (! $ownerExists) {
                abort(422, 'Provided owner_id does not belong to the active tenant.');
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $dedupeKeys
     */
    private function findDuplicateLead(int $tenantId, array $row, array $dedupeKeys): ?Lead
    {
        $email = is_string($row['email'] ?? null) ? mb_strtolower(trim((string) $row['email'])) : null;
        $phone = is_string($row['phone'] ?? null) ? trim((string) $row['phone']) : null;

        $useEmail = in_array('email', $dedupeKeys, true) && is_string($email) && $email !== '';
        $usePhone = in_array('phone', $dedupeKeys, true) && is_string($phone) && $phone !== '';

        if (! $useEmail && ! $usePhone) {
            return null;
        }

        return Lead::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where(function ($builder) use ($useEmail, $usePhone, $email, $phone): void {
                if ($useEmail) {
                    $builder->orWhereRaw('LOWER(email) = ?', [$email]);
                }

                if ($usePhone) {
                    $builder->orWhere('phone', $phone);
                }
            })
            ->orderBy('id')
            ->first();
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $importMeta
     */
    private function createLeadFromImportRow(
        int $tenantId,
        array $row,
        ?int $actorId,
        string $source,
        array $importMeta
    ): Lead {
        $lead = Lead::query()->withoutTenancy()->create([
            'tenant_id' => $tenantId,
            'team_id' => $row['team_id'] ?? null,
            'owner_id' => $row['owner_id'] ?? null,
            'first_name' => $row['first_name'] ?? null,
            'last_name' => $row['last_name'] ?? null,
            'email' => $row['email'] ?? null,
            'email_consent' => array_key_exists('email_consent', $row) ? (bool) $row['email_consent'] : true,
            'consent_updated_at' => now(),
            'phone' => $row['phone'] ?? null,
            'company' => $row['company'] ?? null,
            'city' => $row['city'] ?? null,
            'country_code' => $row['country_code'] ?? null,
            'interest' => $row['interest'] ?? null,
            'service' => $row['service'] ?? null,
            'title' => $row['title'] ?? null,
            'status' => $row['status'] ?? 'new',
            'source' => $row['source'] ?? $source,
            'score' => $row['score'] ?? 0,
            'locale' => $row['locale'] ?? null,
            'meta' => $row['meta'] ?? [],
            'settings' => $row['settings'] ?? [],
        ]);

        $this->syncTags($lead, $tenantId, $row['tags'] ?? []);
        $this->customFieldService->upsertLeadValues(
            $lead,
            is_array($row['custom_fields'] ?? null) ? $row['custom_fields'] : []
        );

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'type' => 'lead.imported',
            'subject_type' => Lead::class,
            'subject_id' => (int) $lead->id,
            'description' => 'Lead imported from admin module.',
            'properties' => [
                'source' => $lead->source,
            ] + $importMeta,
        ]);

        return $lead;
    }

    /**
     * Apply dedupe update policy (overwrite with imported values).
     *
     * @param array<string, mixed> $row
     */
    private function applyUpdatePolicy(Lead $lead, array $row, int $tenantId): Lead
    {
        $originalStatus = (string) $lead->status;
        $attrs = [];

        foreach ([
            'team_id',
            'owner_id',
            'first_name',
            'last_name',
            'email',
            'phone',
            'company',
            'city',
            'country_code',
            'interest',
            'service',
            'title',
            'status',
            'source',
            'score',
            'locale',
        ] as $field) {
            if (array_key_exists($field, $row)) {
                $attrs[$field] = $row[$field];
            }
        }

        if (array_key_exists('email_consent', $row)) {
            $attrs['email_consent'] = (bool) $row['email_consent'];
            $attrs['consent_updated_at'] = now();
        }

        if (array_key_exists('meta', $row) && is_array($row['meta'])) {
            $existingMeta = is_array($lead->meta) ? $lead->meta : [];
            $attrs['meta'] = array_replace_recursive($existingMeta, $row['meta']);
        }

        if (array_key_exists('settings', $row) && is_array($row['settings'])) {
            $existingSettings = is_array($lead->settings) ? $lead->settings : [];
            $attrs['settings'] = array_replace_recursive($existingSettings, $row['settings']);
        }

        if ($attrs !== []) {
            $lead->fill($attrs)->save();
        }

        $this->syncTags($lead, $tenantId, $row['tags'] ?? []);

        if (is_array($row['custom_fields'] ?? null)) {
            $this->customFieldService->upsertLeadValues($lead, $row['custom_fields']);
        }

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => $tenantId,
            'actor_id' => null,
            'type' => 'lead.import.duplicate.updated',
            'subject_type' => Lead::class,
            'subject_id' => (int) $lead->id,
            'description' => 'Existing lead updated by import dedupe policy.',
            'properties' => [
                'previous_status' => $originalStatus,
                'current_status' => (string) $lead->status,
            ],
        ]);

        return $lead->refresh();
    }

    /**
     * Apply dedupe merge policy (fill missing values, preserve existing values).
     *
     * @param array<string, mixed> $row
     */
    private function applyMergePolicy(Lead $lead, array $row, int $tenantId): Lead
    {
        $attrs = [];

        foreach ([
            'first_name',
            'last_name',
            'email',
            'phone',
            'company',
            'city',
            'country_code',
            'interest',
            'service',
            'title',
            'source',
            'locale',
        ] as $field) {
            $current = $lead->getAttribute($field);

            if ($this->isBlankValue($current) && ! $this->isBlankValue($row[$field] ?? null)) {
                $attrs[$field] = $row[$field];
            }
        }

        if ($lead->team_id === null && is_numeric($row['team_id'] ?? null)) {
            $attrs['team_id'] = (int) $row['team_id'];
        }

        if ($lead->owner_id === null && is_numeric($row['owner_id'] ?? null)) {
            $attrs['owner_id'] = (int) $row['owner_id'];
        }

        if (is_numeric($row['score'] ?? null)) {
            $attrs['score'] = max((int) $lead->score, (int) $row['score']);
        }

        if (array_key_exists('email_consent', $row) && $row['email_consent'] === false && $lead->email_consent !== false) {
            $attrs['email_consent'] = false;
            $attrs['consent_updated_at'] = now();
        }

        if (is_array($row['meta'] ?? null)) {
            $incomingMeta = $row['meta'];
            $existingMeta = is_array($lead->meta) ? $lead->meta : [];
            $attrs['meta'] = array_replace_recursive($incomingMeta, $existingMeta);
        }

        if (is_array($row['settings'] ?? null)) {
            $incomingSettings = $row['settings'];
            $existingSettings = is_array($lead->settings) ? $lead->settings : [];
            $attrs['settings'] = array_replace_recursive($incomingSettings, $existingSettings);
        }

        if ($attrs !== []) {
            $lead->fill($attrs)->save();
        }

        $this->syncTags($lead, $tenantId, $row['tags'] ?? []);

        if (is_array($row['custom_fields'] ?? null)) {
            $this->customFieldService->upsertLeadValues($lead, $row['custom_fields']);
        }

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => $tenantId,
            'actor_id' => null,
            'type' => 'lead.import.duplicate.merged',
            'subject_type' => Lead::class,
            'subject_id' => (int) $lead->id,
            'description' => 'Existing lead merged with imported row.',
            'properties' => [
                'dedupe_policy' => 'merge',
            ],
        ]);

        return $lead->refresh();
    }

    /**
     * @param list<mixed> $tags
     */
    private function syncTags(Lead $lead, int $tenantId, array $tags): void
    {
        if ($tags === []) {
            return;
        }

        $tagIds = $this->resolveTagIdsForTenant($tenantId, $tags);

        if ($tagIds->isEmpty()) {
            return;
        }

        $lead->tags()->syncWithoutDetaching(
            $tagIds->mapWithKeys(
                static fn (int $tagId): array => [$tagId => ['tenant_id' => $tenantId]]
            )->all()
        );
    }

    /**
     * @param list<mixed> $tagNames
     * @return Collection<int, int>
     */
    private function resolveTagIdsForTenant(int $tenantId, array $tagNames): Collection
    {
        $resolved = collect();

        foreach ($tagNames as $rawName) {
            $name = trim((string) $rawName);

            if ($name === '') {
                continue;
            }

            $tag = Tag::query()->withoutTenancy()->firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'slug' => Str::slug($name),
                ],
                [
                    'name' => $name,
                ]
            );

            $resolved->push((int) $tag->id);
        }

        return $resolved
            ->filter(static fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
    }

    /**
     * @param array<string, mixed> $importMeta
     */
    private function emitLeadCreatedEvent(Lead $lead, string $source, array $importMeta): void
    {
        $this->eventService->emit(
            eventName: 'lead.created',
            tenantId: (int) $lead->tenant_id,
            subjectType: Lead::class,
            subjectId: (int) $lead->id,
            payload: [
                'source' => $source,
                'status' => $lead->status,
            ] + $importMeta,
        );
    }

    /**
     * @param array<string, mixed> $importMeta
     */
    private function emitLeadUpdatedEvent(Lead $lead, string $source, array $importMeta, string $dedupePolicy): void
    {
        $this->eventService->emit(
            eventName: 'lead.updated',
            tenantId: (int) $lead->tenant_id,
            subjectType: Lead::class,
            subjectId: (int) $lead->id,
            payload: [
                'source' => $source,
                'status' => $lead->status,
                'dedupe_policy' => $dedupePolicy,
            ] + $importMeta,
        );
    }

    private function normalizeDedupePolicy(mixed $dedupePolicy): string
    {
        $value = mb_strtolower(trim((string) $dedupePolicy));

        if (! in_array($value, self::DEDUPE_POLICIES, true)) {
            return 'skip';
        }

        return $value;
    }

    /**
     * @param mixed $rawKeys
     * @return list<string>
     */
    private function normalizeDedupeKeys(mixed $rawKeys): array
    {
        if (! is_array($rawKeys)) {
            return ['email', 'phone'];
        }

        $keys = collect($rawKeys)
            ->map(static fn (mixed $item): string => mb_strtolower(trim((string) $item)))
            ->filter(static fn (string $item): bool => in_array($item, self::DEDUPE_KEYS, true))
            ->unique()
            ->values()
            ->all();

        return $keys !== [] ? $keys : ['email', 'phone'];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildScheduleImportOptions(LeadImportSchedule $schedule): array
    {
        $preset = $schedule->relationLoaded('preset')
            ? $schedule->preset
            : $schedule->preset()->first();

        $presetMapping = is_array($preset?->mapping) ? $preset->mapping : [];
        $scheduleMapping = is_array($schedule->mapping) ? $schedule->mapping : [];
        $mapping = $this->mergeImportArrays($presetMapping, $scheduleMapping);

        $presetDefaults = is_array($preset?->defaults) ? $preset->defaults : [];
        $scheduleDefaults = is_array($schedule->defaults) ? $schedule->defaults : [];
        $defaults = $this->mergeImportArrays($presetDefaults, $scheduleDefaults);

        $presetDedupeKeys = is_array($preset?->dedupe_keys) ? $preset->dedupe_keys : ['email', 'phone'];
        $scheduleDedupeKeys = is_array($schedule->dedupe_keys) ? $schedule->dedupe_keys : $presetDedupeKeys;

        $dedupePolicy = is_string($schedule->dedupe_policy) && trim($schedule->dedupe_policy) !== ''
            ? $schedule->dedupe_policy
            : (is_string($preset?->dedupe_policy) ? (string) $preset->dedupe_policy : 'skip');

        return [
            'mapping' => $mapping,
            'defaults' => $defaults,
            'dedupe_policy' => $dedupePolicy,
            'dedupe_keys' => $scheduleDedupeKeys,
            'auto_assign' => (bool) $schedule->auto_assign,
            'source' => 'scheduled_import',
            'actor_id' => $schedule->updated_by,
            'import_meta' => [
                'schedule_id' => (int) $schedule->id,
                'preset_id' => $schedule->preset_id !== null ? (int) $schedule->preset_id : null,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function mergeImportArrays(array $base, array $override): array
    {
        if ($base === []) {
            return $override;
        }

        if ($override === []) {
            return $base;
        }

        if (! Arr::isAssoc($base) || ! Arr::isAssoc($override)) {
            return $override;
        }

        return array_replace_recursive($base, $override);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchSourceRows(LeadImportSchedule $schedule): array
    {
        $sourceType = mb_strtolower(trim((string) $schedule->source_type));
        $config = is_array($schedule->source_config) ? $schedule->source_config : [];

        if ($sourceType === 'url') {
            return $this->fetchRowsFromUrl($config);
        }

        if ($sourceType === 'sftp') {
            return $this->fetchRowsFromSftp($config);
        }

        throw new RuntimeException('Unsupported import source_type "'.$sourceType.'".');
    }

    /**
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>
     */
    private function fetchRowsFromUrl(array $config): array
    {
        $url = trim((string) ($config['url'] ?? ''));

        if ($url === '') {
            throw new RuntimeException('URL source requires source_config.url.');
        }

        $client = Http::timeout(max(5, (int) ($config['timeout_seconds'] ?? 30)));

        $username = trim((string) ($config['username'] ?? ''));
        $password = (string) ($config['password'] ?? '');

        if ($username !== '' || $password !== '') {
            $client = $client->withBasicAuth($username, $password);
        }

        $token = trim((string) ($config['token'] ?? ''));
        if ($token !== '') {
            $client = $client->withToken($token);
        }

        $response = $client->get($url);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to fetch import URL: HTTP '.$response->status().'.');
        }

        $content = (string) $response->body();
        $format = is_string($config['format'] ?? null) ? (string) $config['format'] : '';

        if ($format === '') {
            $format = $this->detectFormat($url, (string) $response->header('Content-Type', ''));
        }

        return $this->parseRowsFromContent($content, $format);
    }

    /**
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>
     */
    private function fetchRowsFromSftp(array $config): array
    {
        $path = trim((string) ($config['path'] ?? ''));

        if ($path === '') {
            throw new RuntimeException('SFTP source requires source_config.path.');
        }

        $driverConfig = [
            'driver' => 'sftp',
            'host' => trim((string) ($config['host'] ?? '')),
            'port' => max(1, (int) ($config['port'] ?? 22)),
            'username' => trim((string) ($config['username'] ?? '')),
            'password' => (string) ($config['password'] ?? ''),
            'privateKey' => is_string($config['private_key'] ?? null) ? (string) $config['private_key'] : null,
            'passphrase' => is_string($config['passphrase'] ?? null) ? (string) $config['passphrase'] : null,
            'root' => trim((string) ($config['root'] ?? '')),
            'timeout' => max(5, (int) ($config['timeout_seconds'] ?? 30)),
        ];

        if ($driverConfig['host'] === '' || $driverConfig['username'] === '') {
            throw new RuntimeException('SFTP source requires host and username.');
        }

        try {
            $disk = Storage::build(array_filter($driverConfig, static fn (mixed $value): bool => $value !== null));
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'SFTP driver is not available. Install and configure Flysystem SFTP support (league/flysystem-sftp-v3).'
            );
        }

        if (! $disk->exists($path)) {
            throw new RuntimeException('SFTP file was not found at path: '.$path);
        }

        $content = (string) $disk->get($path);
        $format = is_string($config['format'] ?? null) ? (string) $config['format'] : '';

        if ($format === '') {
            $format = $this->detectFormat($path, '');
        }

        return $this->parseRowsFromContent($content, $format);
    }

    private function detectFormat(string $pathOrUrl, string $contentType): string
    {
        $contentTypeLower = mb_strtolower(trim($contentType));

        if (str_contains($contentTypeLower, 'application/json') || str_contains($contentTypeLower, '+json')) {
            return 'json';
        }

        $path = parse_url($pathOrUrl, PHP_URL_PATH);
        $extension = mb_strtolower((string) pathinfo((string) $path, PATHINFO_EXTENSION));

        return $extension === 'json' ? 'json' : 'csv';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseRowsFromContent(string $content, string $format): array
    {
        $format = mb_strtolower(trim($format));

        if ($format === 'json') {
            $decoded = json_decode($content, true);

            if (! is_array($decoded)) {
                throw new RuntimeException('JSON import content could not be parsed.');
            }

            if (Arr::isAssoc($decoded) && is_array($decoded['rows'] ?? null)) {
                $decoded = $decoded['rows'];
            }

            $rows = [];

            foreach ($decoded as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }

            if ($rows === []) {
                throw new RuntimeException('Import source did not contain any rows.');
            }

            return $rows;
        }

        return $this->parseCsvRows($content);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseCsvRows(string $content): array
    {
        $stream = fopen('php://temp', 'r+');

        if (! is_resource($stream)) {
            throw new RuntimeException('Failed to open temporary stream for CSV parsing.');
        }

        fwrite($stream, $content);
        rewind($stream);

        $headers = fgetcsv($stream);

        if (! is_array($headers) || $headers === []) {
            fclose($stream);
            throw new RuntimeException('CSV import source is missing a header row.');
        }

        $headers = array_map(static function (mixed $value): string {
            $header = trim((string) $value);
            $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;

            return $header;
        }, $headers);

        $rows = [];

        while (($values = fgetcsv($stream)) !== false) {
            if (! is_array($values)) {
                continue;
            }

            $isEmpty = collect($values)->every(static fn (mixed $value): bool => trim((string) $value) === '');
            if ($isEmpty) {
                continue;
            }

            if (count($values) < count($headers)) {
                $values = array_pad($values, count($headers), null);
            }

            $row = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }

                $row[$header] = $values[$index] ?? null;
            }

            $rows[] = $row;
        }

        fclose($stream);

        if ($rows === []) {
            throw new RuntimeException('Import source did not contain any data rows.');
        }

        return $rows;
    }

    private function resolveScheduleTimezone(LeadImportSchedule $schedule): string
    {
        $timezone = trim((string) ($schedule->timezone ?? ''));

        if ($timezone !== '') {
            return $timezone;
        }

        return 'UTC';
    }

    private function isBlankValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }
}
