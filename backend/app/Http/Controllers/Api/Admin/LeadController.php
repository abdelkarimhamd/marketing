<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use App\Services\LeadAssignmentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    /**
     * Display a paginated list of leads with filters/search.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:50'],
            'source' => ['nullable', 'string', 'max:100'],
            'owner_id' => ['nullable', 'integer'],
            'team_id' => ['nullable', 'integer'],
            'city' => ['nullable', 'string', 'max:150'],
            'interest' => ['nullable', 'string', 'max:150'],
            'service' => ['nullable', 'string', 'max:150'],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort_by' => ['nullable', Rule::in(['id', 'created_at', 'updated_at', 'score', 'status'])],
            'sort_direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $query = Lead::query()
            ->with(['owner:id,name,email', 'team:id,name', 'tags:id,name,slug,color']);

        $query->when(isset($filters['status']), fn (Builder $q): Builder => $q->where('status', $filters['status']));
        $query->when(isset($filters['source']), fn (Builder $q): Builder => $q->where('source', $filters['source']));
        $query->when(isset($filters['owner_id']), fn (Builder $q): Builder => $q->where('owner_id', $filters['owner_id']));
        $query->when(isset($filters['team_id']), fn (Builder $q): Builder => $q->where('team_id', $filters['team_id']));
        $query->when(isset($filters['city']), fn (Builder $q): Builder => $q->where('city', $filters['city']));
        $query->when(isset($filters['interest']), fn (Builder $q): Builder => $q->where('interest', $filters['interest']));
        $query->when(isset($filters['service']), fn (Builder $q): Builder => $q->where('service', $filters['service']));

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('company', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['tag_ids'])) {
            $tagIds = collect($filters['tag_ids'])
                ->map(static fn (mixed $id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->unique()
                ->values();

            if ($tagIds->isNotEmpty()) {
                $query->whereHas('tags', function (Builder $builder) use ($tagIds): void {
                    $builder->whereIn('tags.id', $tagIds);
                });
            }
        }

        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDirection = $filters['sort_direction'] ?? 'desc';
        $perPage = (int) ($filters['per_page'] ?? 15);

        $leads = $query
            ->orderBy($sortBy, $sortDirection)
            ->paginate($perPage)
            ->withQueryString();

        return response()->json($leads);
    }

    /**
     * Store a new lead.
     */
    public function store(Request $request, LeadAssignmentService $assignmentService): JsonResponse
    {
        $this->authorizeAdmin($request);

        $tenantId = $this->resolveTenantIdForWrite($request);
        $payload = $this->validateLeadPayload($request, isUpdate: false);
        $this->validateTenantReferences($tenantId, $payload);

        $lead = DB::transaction(function () use ($payload, $tenantId): Lead {
            $lead = Lead::query()->withoutTenancy()->create([
                'tenant_id' => $tenantId,
                'team_id' => $payload['team_id'] ?? null,
                'owner_id' => $payload['owner_id'] ?? null,
                'first_name' => $payload['first_name'] ?? null,
                'last_name' => $payload['last_name'] ?? null,
                'email' => $payload['email'] ?? null,
                'email_consent' => $payload['email_consent'] ?? true,
                'consent_updated_at' => now(),
                'phone' => $payload['phone'] ?? null,
                'company' => $payload['company'] ?? null,
                'city' => $payload['city'] ?? null,
                'interest' => $payload['interest'] ?? null,
                'service' => $payload['service'] ?? null,
                'title' => $payload['title'] ?? null,
                'status' => $payload['status'] ?? 'new',
                'source' => $payload['source'] ?? 'admin',
                'score' => $payload['score'] ?? 0,
                'meta' => $payload['meta'] ?? null,
                'settings' => $payload['settings'] ?? null,
            ]);

            $tagIds = $this->resolveTagIdsForTenant(
                tenantId: $tenantId,
                tagIds: $payload['tag_ids'] ?? [],
                tagNames: $payload['tags'] ?? [],
            );

            if ($tagIds->isNotEmpty()) {
                $lead->tags()->sync($tagIds->mapWithKeys(
                    static fn (int $tagId): array => [$tagId => ['tenant_id' => $tenantId]]
                )->all());
            }

            Activity::query()->withoutTenancy()->create([
                'tenant_id' => $tenantId,
                'actor_id' => $requestUserId = optional(request()->user())->id,
                'type' => 'lead.admin.created',
                'subject_type' => Lead::class,
                'subject_id' => $lead->id,
                'description' => 'Lead created from admin module.',
                'properties' => [
                    'user_id' => $requestUserId,
                ],
            ]);

            return $lead;
        });

        if (($payload['auto_assign'] ?? true) && $lead->owner_id === null) {
            $assignmentService->assignLead($lead->refresh(), 'manual');
        }

        return response()->json([
            'message' => 'Lead created successfully.',
            'lead' => $lead->refresh()->load(['owner:id,name,email', 'team:id,name', 'tags:id,name,slug,color']),
        ], 201);
    }

    /**
     * Show a lead.
     */
    public function show(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json([
            'lead' => $lead->load(['owner:id,name,email', 'team:id,name', 'tags:id,name,slug,color']),
        ]);
    }

    /**
     * Update a lead.
     */
    public function update(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeAdmin($request);

        $payload = $this->validateLeadPayload($request, isUpdate: true);
        $this->validateTenantReferences((int) $lead->tenant_id, $payload);

        DB::transaction(function () use ($request, $lead, $payload): void {
            $lead->fill([
                'team_id' => $payload['team_id'] ?? $lead->team_id,
                'owner_id' => array_key_exists('owner_id', $payload) ? $payload['owner_id'] : $lead->owner_id,
                'first_name' => $payload['first_name'] ?? $lead->first_name,
                'last_name' => $payload['last_name'] ?? $lead->last_name,
                'email' => $payload['email'] ?? $lead->email,
                'email_consent' => $payload['email_consent'] ?? $lead->email_consent,
                'consent_updated_at' => array_key_exists('email_consent', $payload) ? now() : $lead->consent_updated_at,
                'phone' => $payload['phone'] ?? $lead->phone,
                'company' => $payload['company'] ?? $lead->company,
                'city' => $payload['city'] ?? $lead->city,
                'interest' => $payload['interest'] ?? $lead->interest,
                'service' => $payload['service'] ?? $lead->service,
                'title' => $payload['title'] ?? $lead->title,
                'status' => $payload['status'] ?? $lead->status,
                'source' => $payload['source'] ?? $lead->source,
                'score' => $payload['score'] ?? $lead->score,
                'meta' => $payload['meta'] ?? $lead->meta,
                'settings' => $payload['settings'] ?? $lead->settings,
            ]);

            $lead->save();

            if (array_key_exists('tag_ids', $payload) || array_key_exists('tags', $payload)) {
                $tagIds = $this->resolveTagIdsForTenant(
                    tenantId: (int) $lead->tenant_id,
                    tagIds: $payload['tag_ids'] ?? [],
                    tagNames: $payload['tags'] ?? [],
                );

                $lead->tags()->sync($tagIds->mapWithKeys(
                    fn (int $tagId): array => [$tagId => ['tenant_id' => $lead->tenant_id]]
                )->all());
            }

            Activity::query()->withoutTenancy()->create([
                'tenant_id' => $lead->tenant_id,
                'actor_id' => optional($request->user())->id,
                'type' => 'lead.admin.updated',
                'subject_type' => Lead::class,
                'subject_id' => $lead->id,
                'description' => 'Lead updated from admin module.',
                'properties' => [
                    'user_id' => optional($request->user())->id,
                ],
            ]);
        });

        return response()->json([
            'message' => 'Lead updated successfully.',
            'lead' => $lead->refresh()->load(['owner:id,name,email', 'team:id,name', 'tags:id,name,slug,color']),
        ]);
    }

    /**
     * Delete a lead.
     */
    public function destroy(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeAdmin($request);

        $lead->delete();

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => $lead->tenant_id,
            'actor_id' => optional($request->user())->id,
            'type' => 'lead.admin.deleted',
            'subject_type' => Lead::class,
            'subject_id' => $lead->id,
            'description' => 'Lead deleted from admin module.',
            'properties' => [
                'user_id' => optional($request->user())->id,
            ],
        ]);

        return response()->json([
            'message' => 'Lead deleted successfully.',
        ]);
    }

    /**
     * Run bulk actions on selected leads.
     */
    public function bulk(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $tenantId = $this->resolveTenantIdForWrite($request);

        $payload = $request->validate([
            'action' => ['required', Rule::in(['assign', 'tag', 'status'])],
            'lead_ids' => ['required', 'array', 'min:1', 'max:500'],
            'lead_ids.*' => ['integer', 'min:1'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'status' => ['nullable', 'string', 'max:50'],
            'tag_ids' => ['nullable', 'array', 'max:100'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
            'tags' => ['nullable', 'array', 'max:100'],
            'tags.*' => ['string', 'max:80'],
        ]);

        $leadIds = collect($payload['lead_ids'])
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        $leads = Lead::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $leadIds)
            ->get();

        if ($leads->isEmpty()) {
            return response()->json([
                'message' => 'No leads matched the provided lead IDs in the active tenant.',
                'affected' => 0,
            ]);
        }

        DB::transaction(function () use ($request, $payload, $tenantId, $leads): void {
            if ($payload['action'] === 'assign') {
                $this->applyBulkAssign($request, $tenantId, $leads, $payload);
            }

            if ($payload['action'] === 'tag') {
                $this->applyBulkTag($request, $tenantId, $leads, $payload);
            }

            if ($payload['action'] === 'status') {
                $this->applyBulkStatus($request, $leads, $payload);
            }
        });

        return response()->json([
            'message' => 'Bulk action applied successfully.',
            'action' => $payload['action'],
            'affected' => $leads->count(),
        ]);
    }

    /**
     * Import leads and auto-assign when enabled.
     */
    public function import(Request $request, LeadAssignmentService $assignmentService): JsonResponse
    {
        $this->authorizeAdmin($request);

        $tenantId = $this->resolveTenantIdForWrite($request);

        $payload = $request->validate([
            'auto_assign' => ['nullable', 'boolean'],
            'leads' => ['required', 'array', 'min:1', 'max:1000'],
            'leads.*.first_name' => ['nullable', 'string', 'max:100'],
            'leads.*.last_name' => ['nullable', 'string', 'max:100'],
            'leads.*.email' => ['nullable', 'email:rfc', 'max:255', 'required_without:leads.*.phone'],
            'leads.*.phone' => ['nullable', 'string', 'max:32', 'required_without:leads.*.email'],
            'leads.*.company' => ['nullable', 'string', 'max:255'],
            'leads.*.city' => ['nullable', 'string', 'max:150'],
            'leads.*.interest' => ['nullable', 'string', 'max:150'],
            'leads.*.service' => ['nullable', 'string', 'max:150'],
            'leads.*.title' => ['nullable', 'string', 'max:255'],
            'leads.*.status' => ['nullable', 'string', 'max:50'],
            'leads.*.source' => ['nullable', 'string', 'max:100'],
            'leads.*.score' => ['nullable', 'integer', 'min:0'],
            'leads.*.email_consent' => ['nullable', 'boolean'],
            'leads.*.team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'leads.*.owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'leads.*.meta' => ['nullable', 'array'],
            'leads.*.settings' => ['nullable', 'array'],
            'leads.*.tags' => ['nullable', 'array'],
            'leads.*.tags.*' => ['string', 'max:80'],
        ]);

        $autoAssign = (bool) ($payload['auto_assign'] ?? true);
        $createdIds = [];
        $assignedCount = 0;

        DB::transaction(function () use (
            $tenantId,
            $payload,
            $assignmentService,
            $autoAssign,
            &$createdIds,
            &$assignedCount
        ): void {
            foreach ($payload['leads'] as $row) {
                $this->validateTenantReferences($tenantId, $row);

                if (empty($row['email']) && empty($row['phone'])) {
                    abort(422, 'Each imported lead must include email or phone.');
                }

                $lead = Lead::query()->withoutTenancy()->create([
                    'tenant_id' => $tenantId,
                    'team_id' => $row['team_id'] ?? null,
                    'owner_id' => $row['owner_id'] ?? null,
                    'first_name' => $row['first_name'] ?? null,
                    'last_name' => $row['last_name'] ?? null,
                    'email' => $row['email'] ?? null,
                    'email_consent' => $row['email_consent'] ?? true,
                    'consent_updated_at' => now(),
                    'phone' => $row['phone'] ?? null,
                    'company' => $row['company'] ?? null,
                    'city' => $row['city'] ?? null,
                    'interest' => $row['interest'] ?? null,
                    'service' => $row['service'] ?? null,
                    'title' => $row['title'] ?? null,
                    'status' => $row['status'] ?? 'new',
                    'source' => $row['source'] ?? 'import',
                    'score' => $row['score'] ?? 0,
                    'meta' => $row['meta'] ?? null,
                    'settings' => $row['settings'] ?? null,
                ]);

                $tagIds = $this->resolveTagIdsForTenant(
                    tenantId: $tenantId,
                    tagIds: [],
                    tagNames: $row['tags'] ?? [],
                );

                if ($tagIds->isNotEmpty()) {
                    $lead->tags()->sync($tagIds->mapWithKeys(
                        static fn (int $tagId): array => [$tagId => ['tenant_id' => $tenantId]]
                    )->all());
                }

                Activity::query()->withoutTenancy()->create([
                    'tenant_id' => $tenantId,
                    'actor_id' => optional(request()->user())->id,
                    'type' => 'lead.imported',
                    'subject_type' => Lead::class,
                    'subject_id' => $lead->id,
                    'description' => 'Lead imported from admin module.',
                    'properties' => [
                        'source' => $lead->source,
                    ],
                ]);

                if ($autoAssign && $lead->owner_id === null) {
                    $assignee = $assignmentService->assignLead($lead, 'import');

                    if ($assignee !== null) {
                        $assignedCount++;
                    }
                }

                $createdIds[] = $lead->id;
            }
        });

        return response()->json([
            'message' => 'Leads imported successfully.',
            'created_count' => count($createdIds),
            'assigned_count' => $assignedCount,
            'lead_ids' => $createdIds,
        ], 201);
    }

    /**
     * Validate lead payload for create/update.
     *
     * @return array<string, mixed>
     */
    private function validateLeadPayload(Request $request, bool $isUpdate): array
    {
        $prefix = $isUpdate ? 'sometimes' : 'nullable';

        $rules = [
            'first_name' => [$prefix, 'nullable', 'string', 'max:100'],
            'last_name' => [$prefix, 'nullable', 'string', 'max:100'],
            'email' => [$prefix, 'nullable', 'email:rfc', 'max:255'],
            'phone' => [$prefix, 'nullable', 'string', 'max:32'],
            'company' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:150'],
            'interest' => ['sometimes', 'nullable', 'string', 'max:150'],
            'service' => ['sometimes', 'nullable', 'string', 'max:150'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'string', 'max:50'],
            'source' => ['sometimes', 'nullable', 'string', 'max:100'],
            'score' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'team_id' => ['sometimes', 'nullable', 'integer', 'exists:teams,id'],
            'owner_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'email_consent' => ['sometimes', 'boolean'],
            'meta' => ['sometimes', 'nullable', 'array'],
            'settings' => ['sometimes', 'nullable', 'array'],
            'tag_ids' => ['sometimes', 'array', 'max:100'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
            'tags' => ['sometimes', 'array', 'max:100'],
            'tags.*' => ['string', 'max:80'],
            'auto_assign' => ['sometimes', 'boolean'],
        ];

        if (! $isUpdate) {
            $rules['email'][] = 'required_without:phone';
            $rules['phone'][] = 'required_without:email';
            $rules['first_name'][0] = 'nullable';
            $rules['last_name'][0] = 'nullable';
        }

        return $request->validate($rules);
    }

    /**
     * Resolve final tenant id for write operations.
     */
    private function resolveTenantIdForWrite(Request $request): int
    {
        $tenantId = $request->attributes->get('tenant_id');

        if (is_int($tenantId) && $tenantId > 0) {
            return $tenantId;
        }

        if (is_numeric($request->input('tenant_id')) && (int) $request->input('tenant_id') > 0) {
            return (int) $request->input('tenant_id');
        }

        abort(422, 'Tenant context is required for this operation. Select/supply tenant_id first.');
    }

    /**
     * Ensure referenced entities belong to tenant context.
     *
     * @param array<string, mixed> $payload
     */
    private function validateTenantReferences(int $tenantId, array $payload): void
    {
        if (array_key_exists('team_id', $payload) && $payload['team_id'] !== null) {
            $teamExists = Team::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->whereKey($payload['team_id'])
                ->exists();

            if (! $teamExists) {
                abort(422, 'Provided team_id does not belong to the active tenant.');
            }
        }

        if (array_key_exists('owner_id', $payload) && $payload['owner_id'] !== null) {
            $ownerExists = User::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->whereKey($payload['owner_id'])
                ->exists();

            if (! $ownerExists) {
                abort(422, 'Provided owner_id does not belong to the active tenant.');
            }
        }
    }

    /**
     * Ensure caller has admin permission.
     */
    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        if (! $user || ! $user->isAdmin()) {
            abort(403, 'Admin permissions are required.');
        }
    }

    /**
     * Resolve tenant-scoped tag IDs from IDs and names.
     *
     * @param list<mixed> $tagIds
     * @param list<mixed> $tagNames
     * @return Collection<int, int>
     */
    private function resolveTagIdsForTenant(int $tenantId, array $tagIds, array $tagNames): Collection
    {
        $resolvedIds = collect();

        if ($tagIds !== []) {
            $existingIds = Tag::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', collect($tagIds)->map(static fn (mixed $id): int => (int) $id))
                ->pluck('id');

            $resolvedIds = $resolvedIds->merge($existingIds);
        }

        foreach ($tagNames as $tagNameRaw) {
            $tagName = trim((string) $tagNameRaw);

            if ($tagName === '') {
                continue;
            }

            $tag = Tag::query()->withoutTenancy()->firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'slug' => Str::slug($tagName),
                ],
                [
                    'name' => $tagName,
                ]
            );

            $resolvedIds->push($tag->id);
        }

        return $resolvedIds
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
    }

    /**
     * Apply bulk assign action.
     *
     * @param Collection<int, Lead> $leads
     * @param array<string, mixed> $payload
     */
    private function applyBulkAssign(Request $request, int $tenantId, Collection $leads, array $payload): void
    {
        $ownerId = $payload['owner_id'] ?? null;
        $teamId = $payload['team_id'] ?? null;

        if ($ownerId === null && $teamId === null) {
            abort(422, 'owner_id or team_id is required for assign bulk action.');
        }

        if ($ownerId !== null) {
            $ownerExists = User::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->whereKey($ownerId)
                ->exists();

            if (! $ownerExists) {
                abort(422, 'Provided owner_id is not part of the active tenant.');
            }
        }

        if ($teamId !== null) {
            $teamExists = Team::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->whereKey($teamId)
                ->exists();

            if (! $teamExists) {
                abort(422, 'Provided team_id is not part of the active tenant.');
            }
        }

        foreach ($leads as $lead) {
            $lead->forceFill([
                'owner_id' => $ownerId ?? $lead->owner_id,
                'team_id' => $teamId ?? $lead->team_id,
            ])->save();

            Activity::query()->withoutTenancy()->create([
                'tenant_id' => $tenantId,
                'actor_id' => optional($request->user())->id,
                'type' => 'lead.bulk.assigned',
                'subject_type' => Lead::class,
                'subject_id' => $lead->id,
                'description' => 'Lead updated by bulk assign action.',
                'properties' => [
                    'owner_id' => $ownerId,
                    'team_id' => $teamId,
                ],
            ]);
        }
    }

    /**
     * Apply bulk tag action.
     *
     * @param Collection<int, Lead> $leads
     * @param array<string, mixed> $payload
     */
    private function applyBulkTag(Request $request, int $tenantId, Collection $leads, array $payload): void
    {
        $tagIds = $this->resolveTagIdsForTenant(
            tenantId: $tenantId,
            tagIds: $payload['tag_ids'] ?? [],
            tagNames: $payload['tags'] ?? [],
        );

        if ($tagIds->isEmpty()) {
            abort(422, 'At least one valid tag is required for tag bulk action.');
        }

        foreach ($leads as $lead) {
            $lead->tags()->syncWithoutDetaching(
                $tagIds->mapWithKeys(
                    static fn (int $tagId): array => [$tagId => ['tenant_id' => $tenantId]]
                )->all()
            );

            Activity::query()->withoutTenancy()->create([
                'tenant_id' => $tenantId,
                'actor_id' => optional($request->user())->id,
                'type' => 'lead.bulk.tagged',
                'subject_type' => Lead::class,
                'subject_id' => $lead->id,
                'description' => 'Lead updated by bulk tag action.',
                'properties' => [
                    'tag_ids' => $tagIds->all(),
                ],
            ]);
        }
    }

    /**
     * Apply bulk status action.
     *
     * @param Collection<int, Lead> $leads
     * @param array<string, mixed> $payload
     */
    private function applyBulkStatus(Request $request, Collection $leads, array $payload): void
    {
        if (! isset($payload['status'])) {
            abort(422, 'status is required for status bulk action.');
        }

        foreach ($leads as $lead) {
            $lead->forceFill([
                'status' => $payload['status'],
            ])->save();

            Activity::query()->withoutTenancy()->create([
                'tenant_id' => $lead->tenant_id,
                'actor_id' => optional($request->user())->id,
                'type' => 'lead.bulk.status',
                'subject_type' => Lead::class,
                'subject_id' => $lead->id,
                'description' => 'Lead updated by bulk status action.',
                'properties' => [
                    'status' => $payload['status'],
                ],
            ]);
        }
    }
}
