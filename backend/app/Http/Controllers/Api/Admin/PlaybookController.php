<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Playbook;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PlaybookSuggestionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PlaybookController extends Controller
{
    /**
     * List playbooks with search and filters.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'playbooks.view');
        $tenantId = $this->resolveTenantIdStrict($request);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:64'],
            'stage' => ['nullable', 'string', 'max:64'],
            'channel' => ['nullable', 'string', 'max:24'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Playbook::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->with(['creator:id,name,email', 'updater:id,name,email']);

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);

            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('industry', 'like', "%{$search}%")
                    ->orWhere('stage', 'like', "%{$search}%")
                    ->orWhere('channel', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['industry'])) {
            $query->where('industry', $this->normalizeSlug((string) $filters['industry']));
        }

        if (! empty($filters['stage'])) {
            $query->where('stage', $this->normalizeSlug((string) $filters['stage']));
        }

        if (! empty($filters['channel'])) {
            $query->where('channel', strtolower(trim((string) $filters['channel'])));
        }

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        $rows = $query
            ->orderBy('industry')
            ->orderBy('stage')
            ->orderBy('name')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();

        return response()->json($rows);
    }

    /**
     * Show one playbook.
     */
    public function show(Request $request, Playbook $playbook): JsonResponse
    {
        $this->authorizePermission($request, 'playbooks.view');
        $tenantId = $this->resolveTenantIdStrict($request);
        $this->ensurePlaybookTenant($playbook, $tenantId);

        return response()->json([
            'playbook' => $playbook->load(['creator:id,name,email', 'updater:id,name,email']),
        ]);
    }

    /**
     * Create one playbook.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'playbooks.create');
        [$tenantId, $user] = $this->resolveContext($request);
        $payload = $this->validatePayload($request, $tenantId, false);

        $playbook = Playbook::query()
            ->withoutTenancy()
            ->create([
                'tenant_id' => $tenantId,
                'created_by' => $user->id,
                'updated_by' => $user->id,
                'name' => trim((string) $payload['name']),
                'slug' => $payload['slug'] ?? Str::slug((string) $payload['name']),
                'industry' => $this->normalizeSlug((string) $payload['industry']),
                'stage' => $this->normalizeNullableSlug($payload['stage'] ?? null),
                'channel' => $this->normalizeNullableChannel($payload['channel'] ?? null),
                'is_active' => (bool) ($payload['is_active'] ?? true),
                'scripts' => $this->normalizeScripts($payload['scripts'] ?? []),
                'objections' => $this->normalizeObjections($payload['objections'] ?? []),
                'templates' => $this->normalizeTemplates($payload['templates'] ?? []),
                'settings' => is_array($payload['settings'] ?? null) ? $payload['settings'] : [],
            ]);

        return response()->json([
            'message' => 'Playbook created successfully.',
            'playbook' => $playbook->load(['creator:id,name,email', 'updater:id,name,email']),
        ], 201);
    }

    /**
     * Update one playbook.
     */
    public function update(Request $request, Playbook $playbook): JsonResponse
    {
        $this->authorizePermission($request, 'playbooks.update');
        [$tenantId, $user] = $this->resolveContext($request);
        $this->ensurePlaybookTenant($playbook, $tenantId);
        $payload = $this->validatePayload($request, $tenantId, true, $playbook);

        $playbook->forceFill([
            'name' => array_key_exists('name', $payload)
                ? trim((string) $payload['name'])
                : $playbook->name,
            'slug' => $payload['slug'] ?? $playbook->slug,
            'industry' => array_key_exists('industry', $payload)
                ? $this->normalizeSlug((string) $payload['industry'])
                : $playbook->industry,
            'stage' => array_key_exists('stage', $payload)
                ? $this->normalizeNullableSlug($payload['stage'])
                : $playbook->stage,
            'channel' => array_key_exists('channel', $payload)
                ? $this->normalizeNullableChannel($payload['channel'])
                : $playbook->channel,
            'is_active' => array_key_exists('is_active', $payload)
                ? (bool) $payload['is_active']
                : $playbook->is_active,
            'scripts' => array_key_exists('scripts', $payload)
                ? $this->normalizeScripts($payload['scripts'])
                : $playbook->scripts,
            'objections' => array_key_exists('objections', $payload)
                ? $this->normalizeObjections($payload['objections'])
                : $playbook->objections,
            'templates' => array_key_exists('templates', $payload)
                ? $this->normalizeTemplates($payload['templates'])
                : $playbook->templates,
            'settings' => array_key_exists('settings', $payload)
                ? (is_array($payload['settings']) ? $payload['settings'] : [])
                : $playbook->settings,
            'updated_by' => $user->id,
        ])->save();

        return response()->json([
            'message' => 'Playbook updated successfully.',
            'playbook' => $playbook->refresh()->load(['creator:id,name,email', 'updater:id,name,email']),
        ]);
    }

    /**
     * Delete one playbook.
     */
    public function destroy(Request $request, Playbook $playbook): JsonResponse
    {
        $this->authorizePermission($request, 'playbooks.delete');
        $tenantId = $this->resolveTenantIdStrict($request);
        $this->ensurePlaybookTenant($playbook, $tenantId);

        $playbook->delete();

        return response()->json([
            'message' => 'Playbook deleted successfully.',
        ]);
    }

    /**
     * Return contextual suggestions for deal stage or conversation.
     */
    public function suggestions(Request $request, PlaybookSuggestionService $suggestions): JsonResponse
    {
        $this->authorizePermission($request, 'playbooks.suggest');
        $tenantId = $this->resolveTenantIdStrict($request);

        $payload = $request->validate([
            'lead_id' => ['nullable', 'integer', 'exists:leads,id'],
            'thread_key' => ['nullable', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:64'],
            'stage' => ['nullable', 'string', 'max:64'],
            'channel' => ['nullable', 'string', 'max:24'],
            'q' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $lead = $this->resolveLeadContext($tenantId, $payload['lead_id'] ?? null, $payload['thread_key'] ?? null);
        $industry = $this->normalizeNullableSlug($payload['industry'] ?? null)
            ?? $this->inferIndustry($lead);
        $stage = $this->normalizeNullableSlug($payload['stage'] ?? null)
            ?? $this->inferStage($lead);
        $channel = $this->normalizeNullableChannel($payload['channel'] ?? null)
            ?? $this->inferChannel($payload['thread_key'] ?? null, $tenantId);
        $query = $this->normalizeNullableString($payload['q'] ?? null);
        $limit = (int) ($payload['limit'] ?? 8);

        $playbooks = Playbook::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->when($industry !== null, function (Builder $builder) use ($industry): void {
                $builder->where(function (Builder $scope) use ($industry): void {
                    $scope
                        ->where('industry', $industry)
                        ->orWhereIn('industry', ['general', 'any']);
                });
            })
            ->when($stage !== null, function (Builder $builder) use ($stage): void {
                $builder->where(function (Builder $scope) use ($stage): void {
                    $scope
                        ->whereNull('stage')
                        ->orWhere('stage', $stage);
                });
            })
            ->when($channel !== null, function (Builder $builder) use ($channel): void {
                $builder->where(function (Builder $scope) use ($channel): void {
                    $scope
                        ->whereNull('channel')
                        ->orWhere('channel', $channel);
                });
            })
            ->get();

        $ranked = $suggestions
            ->rank(
                playbooks: $playbooks,
                industry: $industry,
                stage: $stage,
                channel: $channel,
                query: $query,
            )
            ->take($limit)
            ->map(function (array $row): array {
                /** @var Playbook $playbook */
                $playbook = $row['playbook'];

                return [
                    'playbook_id' => (int) $playbook->id,
                    'name' => $playbook->name,
                    'industry' => $playbook->industry,
                    'stage' => $playbook->stage,
                    'channel' => $playbook->channel,
                    'score' => (int) $row['score'],
                    'scripts' => $this->normalizeScripts($playbook->scripts),
                    'objections' => $this->normalizeObjections($playbook->objections),
                    'templates' => $this->normalizeTemplates($playbook->templates),
                ];
            })
            ->values();

        return response()->json([
            'context' => [
                'tenant_id' => $tenantId,
                'lead_id' => $lead?->id,
                'industry' => $industry,
                'stage' => $stage,
                'channel' => $channel,
                'q' => $query,
            ],
            'suggestions' => $ranked,
        ]);
    }

    /**
     * Create starter playbooks for quick onboarding.
     */
    public function bootstrap(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'playbooks.create');
        [$tenantId, $user] = $this->resolveContext($request);

        $payload = $request->validate([
            'industries' => ['nullable', 'array'],
            'industries.*' => ['string', 'max:64'],
            'overwrite' => ['nullable', 'boolean'],
        ]);

        $requestedIndustries = collect($payload['industries'] ?? [])
            ->map(fn (mixed $value): string => $this->normalizeSlug((string) $value))
            ->filter()
            ->values();

        $overwrite = (bool) ($payload['overwrite'] ?? false);
        $starters = collect(config('playbooks.starters', []))
            ->filter(static fn (mixed $row): bool => is_array($row));

        if ($requestedIndustries->isNotEmpty()) {
            $starters = $starters->filter(function (array $row) use ($requestedIndustries): bool {
                $industry = trim((string) ($row['industry'] ?? ''));

                return $industry !== '' && $requestedIndustries->contains($industry);
            })->values();
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($starters as $starter) {
            $slug = trim((string) ($starter['slug'] ?? ''));

            if ($slug === '') {
                continue;
            }

            $existing = Playbook::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->where('slug', $slug)
                ->first();

            $data = [
                'name' => trim((string) ($starter['name'] ?? Str::title(str_replace('-', ' ', $slug)))),
                'industry' => $this->normalizeSlug((string) ($starter['industry'] ?? 'general')),
                'stage' => $this->normalizeNullableSlug($starter['stage'] ?? null),
                'channel' => $this->normalizeNullableChannel($starter['channel'] ?? null),
                'is_active' => true,
                'scripts' => $this->normalizeScripts($starter['scripts'] ?? []),
                'objections' => $this->normalizeObjections($starter['objections'] ?? []),
                'templates' => $this->normalizeTemplates($starter['templates'] ?? []),
                'settings' => is_array($starter['settings'] ?? null) ? $starter['settings'] : [],
            ];

            if ($existing === null) {
                Playbook::query()->withoutTenancy()->create([
                    'tenant_id' => $tenantId,
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                    'slug' => $slug,
                    ...$data,
                ]);
                $created++;
                continue;
            }

            if (! $overwrite) {
                $skipped++;
                continue;
            }

            $existing->forceFill([
                ...$data,
                'updated_by' => $user->id,
            ])->save();

            $updated++;
        }

        return response()->json([
            'message' => 'Playbook starters processed successfully.',
            'result' => [
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
            ],
        ]);
    }

    /**
     * Resolve lead context from lead_id or thread_key.
     */
    private function resolveLeadContext(int $tenantId, mixed $leadId, mixed $threadKey): ?Lead
    {
        if (is_numeric($leadId) && (int) $leadId > 0) {
            return Lead::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->whereKey((int) $leadId)
                ->first();
        }

        if (! is_string($threadKey) || trim($threadKey) === '') {
            return null;
        }

        $message = Message::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('thread_key', trim($threadKey))
            ->orderByDesc('id')
            ->first(['lead_id']);

        if ($message === null || $message->lead_id === null) {
            return null;
        }

        return Lead::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->whereKey((int) $message->lead_id)
            ->first();
    }

    /**
     * Resolve channel from latest thread message.
     */
    private function inferChannel(?string $threadKey, int $tenantId): ?string
    {
        if (! is_string($threadKey) || trim($threadKey) === '') {
            return null;
        }

        $channel = Message::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('thread_key', trim($threadKey))
            ->orderByDesc('id')
            ->value('channel');

        return $this->normalizeNullableChannel($channel);
    }

    private function inferStage(?Lead $lead): ?string
    {
        if (! $lead instanceof Lead) {
            return null;
        }

        return $this->normalizeNullableSlug($lead->status);
    }

    private function inferIndustry(?Lead $lead): ?string
    {
        if (! $lead instanceof Lead) {
            return null;
        }

        $metaIndustry = $this->normalizeNullableSlug(data_get($lead->meta, 'industry'));
        if ($metaIndustry !== null) {
            return $metaIndustry;
        }

        $text = Str::lower(trim(
            implode(' ', array_filter([
                (string) ($lead->interest ?? ''),
                (string) ($lead->service ?? ''),
                (string) ($lead->company ?? ''),
            ]))
        ));

        if ($text === '') {
            return null;
        }

        if (Str::contains($text, ['clinic', 'medical', 'dental', 'hospital'])) {
            return 'clinic';
        }

        if (Str::contains($text, ['real estate', 'property', 'broker', 'apartment', 'villa'])) {
            return 'real_estate';
        }

        if (Str::contains($text, ['restaurant', 'cafe', 'food', 'catering'])) {
            return 'restaurant';
        }

        return null;
    }

    /**
     * @param mixed $value
     */
    private function normalizeNullableChannel(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $channel = strtolower(trim($value));

        return $channel !== '' ? $channel : null;
    }

    /**
     * @param mixed $value
     */
    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizeSlug(string $value): string
    {
        return str_replace('-', '_', Str::slug(trim($value), '_'));
    }

    /**
     * @param mixed $value
     */
    private function normalizeNullableSlug(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = $this->normalizeSlug($value);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param mixed $scripts
     * @return array<int, string>
     */
    private function normalizeScripts(mixed $scripts): array
    {
        if (! is_array($scripts)) {
            return [];
        }

        return collect($scripts)
            ->map(static fn (mixed $script): string => trim((string) $script))
            ->filter(static fn (string $script): bool => $script !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param mixed $objections
     * @return array<int, array{objection: string, response: string}>
     */
    private function normalizeObjections(mixed $objections): array
    {
        if (! is_array($objections)) {
            return [];
        }

        return collect($objections)
            ->map(function (mixed $row): ?array {
                if (! is_array($row)) {
                    return null;
                }

                $objection = trim((string) ($row['objection'] ?? ''));
                $response = trim((string) ($row['response'] ?? ''));

                if ($objection === '' && $response === '') {
                    return null;
                }

                return [
                    'objection' => $objection,
                    'response' => $response,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param mixed $templates
     * @return array<int, array{title: string, channel: string, content: string}>
     */
    private function normalizeTemplates(mixed $templates): array
    {
        if (! is_array($templates)) {
            return [];
        }

        return collect($templates)
            ->map(function (mixed $row): ?array {
                if (! is_array($row)) {
                    return null;
                }

                $title = trim((string) ($row['title'] ?? ''));
                $channel = strtolower(trim((string) ($row['channel'] ?? 'email')));
                $content = trim((string) ($row['content'] ?? ''));

                if ($content === '') {
                    return null;
                }

                if ($title === '') {
                    $title = 'Template';
                }

                if (! in_array($channel, ['email', 'sms', 'whatsapp', 'call'], true)) {
                    $channel = 'email';
                }

                return [
                    'title' => $title,
                    'channel' => $channel,
                    'content' => $content,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Validate payload for create/update.
     *
     * @return array<string, mixed>
     */
    private function validatePayload(
        Request $request,
        int $tenantId,
        bool $isUpdate,
        ?Playbook $playbook = null
    ): array {
        $slugRule = Rule::unique('playbooks', 'slug')
            ->where(fn ($builder) => $builder->where('tenant_id', $tenantId));

        if ($playbook !== null) {
            $slugRule->ignore($playbook->id);
        }

        $rules = [
            'name' => ['sometimes', 'string', 'max:150'],
            'slug' => ['sometimes', 'string', 'max:150', $slugRule],
            'industry' => ['sometimes', 'string', 'max:64'],
            'stage' => ['sometimes', 'nullable', 'string', 'max:64'],
            'channel' => ['sometimes', 'nullable', Rule::in(['email', 'sms', 'whatsapp', 'call'])],
            'is_active' => ['sometimes', 'boolean'],
            'scripts' => ['sometimes', 'array'],
            'scripts.*' => ['string', 'max:2000'],
            'objections' => ['sometimes', 'array'],
            'objections.*.objection' => ['required_with:objections', 'string', 'max:2000'],
            'objections.*.response' => ['nullable', 'string', 'max:3000'],
            'templates' => ['sometimes', 'array'],
            'templates.*.title' => ['nullable', 'string', 'max:200'],
            'templates.*.channel' => ['nullable', Rule::in(['email', 'sms', 'whatsapp', 'call'])],
            'templates.*.content' => ['required_with:templates', 'string', 'max:6000'],
            'settings' => ['sometimes', 'array'],
        ];

        if (! $isUpdate) {
            $rules['name'][] = 'required';
            $rules['industry'][] = 'required';
        }

        return $request->validate($rules);
    }

    private function ensurePlaybookTenant(Playbook $playbook, int $tenantId): void
    {
        if ((int) $playbook->tenant_id !== $tenantId) {
            abort(403, 'Playbook does not belong to active tenant context.');
        }
    }

    /**
     * Resolve tenant id from request context.
     */
    private function resolveTenantIdStrict(Request $request): int
    {
        $tenantId = $this->resolveTenantIdForPermission($request, $request->user());

        if ($tenantId !== null && Tenant::query()->whereKey($tenantId)->exists()) {
            return $tenantId;
        }

        abort(422, 'Tenant context is required.');
    }

    /**
     * Resolve tenant and authenticated user.
     *
     * @return array{int, User}
     */
    private function resolveContext(Request $request): array
    {
        $tenantId = $this->resolveTenantIdStrict($request);
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401, 'Authentication is required.');
        }

        return [$tenantId, $user];
    }
}
