<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\HighRiskApproval;
use App\Models\Lead;
use App\Models\LeadImportPreset;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use App\Services\CustomFieldService;
use App\Services\HighRiskApprovalService;
use App\Services\LeadAssignmentService;
use App\Services\LeadEnrichmentService;
use App\Services\LeadImportService;
use App\Services\RealtimeEventService;
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
        $this->authorizePermission($request, 'leads.view');

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
     * Return tenant-scoped assignment options for leads.
     */
    public function assignmentOptions(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'leads.view');

        $tenantId = $this->resolveTenantIdForPermission($request, $request->user());

        if ($tenantId === null) {
            abort(422, 'Tenant context is required to load assignment options.');
        }

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));
        $limit = (int) ($filters['limit'] ?? 100);

        $usersQuery = User::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('is_super_admin', false)
            ->orderBy('name');

        if ($search !== '') {
            $usersQuery->where(function ($builder) use ($search): void {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $teamsQuery = Team::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->orderBy('name');

        if ($search !== '') {
            $teamsQuery->where(function ($builder) use ($search): void {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        $users = $usersQuery
            ->limit($limit)
            ->get(['id', 'name', 'email', 'settings'])
            ->map(static function (User $user): array {
                $bookingLink = is_array($user->settings) ? data_get($user->settings, 'booking.link') : null;

                return [
                    'id' => (int) $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'booking_link' => is_string($bookingLink) && trim($bookingLink) !== '' ? trim($bookingLink) : null,
                ];
            })
            ->values();

        $teams = $teamsQuery
            ->limit($limit)
            ->get(['id', 'name', 'settings'])
            ->map(static function (Team $team): array {
                $bookingLink = is_array($team->settings) ? data_get($team->settings, 'booking.link') : null;

                return [
                    'id' => (int) $team->id,
                    'name' => $team->name,
                    'booking_link' => is_string($bookingLink) && trim($bookingLink) !== '' ? trim($bookingLink) : null,
                ];
            })
            ->values();

        return response()->json([
            'users' => $users,
            'teams' => $teams,
        ]);
    }

    /**
     * Store a new lead.
     */
    public function store(
        Request $request,
        LeadAssignmentService $assignmentService,
        LeadEnrichmentService $leadEnrichmentService,
        CustomFieldService $customFieldService,
        RealtimeEventService $eventService
    ): JsonResponse
    {
        $this->authorizePermission($request, 'leads.create');

        $tenantId = $this->resolveTenantIdForWrite($request);
        $payload = $this->validateLeadPayload($request, isUpdate: false);
        $this->validateTenantReferences($tenantId, $payload);
        $payload = $leadEnrichmentService->enrich($payload);

        $lead = DB::transaction(function () use ($payload, $tenantId, $customFieldService): Lead {
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
                'country_code' => $payload['country_code'] ?? null,
                'interest' => $payload['interest'] ?? null,
                'service' => $payload['service'] ?? null,
                'title' => $payload['title'] ?? null,
                'status' => $payload['status'] ?? 'new',
                'source' => $payload['source'] ?? 'admin',
                'score' => $payload['score'] ?? 0,
                'locale' => $payload['locale'] ?? null,
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

            $customFieldService->upsertLeadValues(
                $lead,
                is_array($payload['custom_fields'] ?? null) ? $payload['custom_fields'] : []
            );

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

        if ($payload['auto_assign'] ?? true) {
            $assignmentService->assignLead($lead->refresh(), 'manual');
        }

        $eventService->emit(
            eventName: 'lead.created',
            tenantId: (int) $lead->tenant_id,
            subjectType: Lead::class,
            subjectId: (int) $lead->id,
            payload: [
                'owner_id' => $lead->owner_id,
                'status' => $lead->status,
                'source' => $lead->source,
            ],
        );

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
        $this->authorizePermission($request, 'leads.view');

        return response()->json([
            'lead' => $lead->load(['owner:id,name,email', 'team:id,name', 'tags:id,name,slug,color']),
        ]);
    }

    /**
     * Update a lead.
     */
    public function update(
        Request $request,
        Lead $lead,
        CustomFieldService $customFieldService,
        RealtimeEventService $eventService
    ): JsonResponse
    {
        $this->authorizePermission($request, 'leads.update');
        $previousStatus = (string) $lead->status;

        $payload = $this->validateLeadPayload($request, isUpdate: true);
        $this->validateTenantReferences((int) $lead->tenant_id, $payload);

        DB::transaction(function () use ($request, $lead, $payload, $customFieldService): void {
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
                'country_code' => $payload['country_code'] ?? $lead->country_code,
                'interest' => $payload['interest'] ?? $lead->interest,
                'service' => $payload['service'] ?? $lead->service,
                'title' => $payload['title'] ?? $lead->title,
                'status' => $payload['status'] ?? $lead->status,
                'source' => $payload['source'] ?? $lead->source,
                'score' => $payload['score'] ?? $lead->score,
                'locale' => $payload['locale'] ?? $lead->locale,
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

            if (array_key_exists('custom_fields', $payload) && is_array($payload['custom_fields'])) {
                $customFieldService->upsertLeadValues($lead, $payload['custom_fields']);
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

        $eventService->emit(
            eventName: 'lead.updated',
            tenantId: (int) $lead->tenant_id,
            subjectType: Lead::class,
            subjectId: (int) $lead->id,
            payload: [
                'owner_id' => $lead->owner_id,
                'status' => $lead->status,
                'source' => $lead->source,
            ],
        );

        if ($previousStatus !== (string) $lead->status) {
            $eventService->emit(
                eventName: 'deal.stage_changed',
                tenantId: (int) $lead->tenant_id,
                subjectType: Lead::class,
                subjectId: (int) $lead->id,
                payload: [
                    'from' => $previousStatus,
                    'to' => (string) $lead->status,
                ],
            );
        }

        return response()->json([
            'message' => 'Lead updated successfully.',
            'lead' => $lead->refresh()->load(['owner:id,name,email', 'team:id,name', 'tags:id,name,slug,color']),
        ]);
    }

    /**
     * Delete a lead.
     */
    public function destroy(
        Request $request,
        Lead $lead,
        HighRiskApprovalService $approvalService
    ): JsonResponse
    {
        $this->authorizePermission($request, 'leads.delete');

        $payload = $request->validate([
            'approval_id' => ['nullable', 'integer', 'min:1'],
            'approval_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $request->user();

        if (! $user instanceof User) {
            abort(401, 'Authentication is required.');
        }

        $approvalDecision = $approvalService->authorizeOrRequest(
            tenantId: (int) $lead->tenant_id,
            actor: $user,
            action: 'lead.delete',
            subjectType: Lead::class,
            subjectId: (int) $lead->id,
            payload: [
                'lead_id' => (int) $lead->id,
                'email' => $lead->email,
            ],
            approvalId: isset($payload['approval_id']) ? (int) $payload['approval_id'] : null,
            reason: $payload['approval_reason'] ?? null,
        );

        if (! ($approvalDecision['execute'] ?? false)) {
            return response()->json([
                'message' => 'Lead deletion requires approval before execution.',
                'requires_approval' => true,
                'approval' => $approvalDecision['approval'] ?? null,
            ], 202);
        }

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

        $approval = $approvalDecision['approval'] ?? null;

        if ($approval instanceof HighRiskApproval) {
            $approvalService->markExecuted(
                approval: $approval,
                executedBy: (int) $user->id,
                executionMeta: [
                    'action' => 'lead.delete',
                    'lead_id' => (int) $lead->id,
                ],
            );
        }

        return response()->json([
            'message' => 'Lead deleted successfully.',
            'approval_id' => $approval instanceof HighRiskApproval ? (int) $approval->id : null,
        ]);
    }

    /**
     * Merge one source lead into one target lead.
     */
    public function merge(
        Request $request,
        HighRiskApprovalService $approvalService,
        RealtimeEventService $eventService
    ): JsonResponse {
        $this->authorizePermission($request, 'leads.delete');

        $tenantId = $this->resolveTenantIdForWrite($request);
        $payload = $request->validate([
            'source_lead_id' => ['required', 'integer', 'min:1', 'different:target_lead_id'],
            'target_lead_id' => ['required', 'integer', 'min:1'],
            'approval_id' => ['nullable', 'integer', 'min:1'],
            'approval_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $request->user();

        if (! $user instanceof User) {
            abort(401, 'Authentication is required.');
        }

        $sourceId = (int) $payload['source_lead_id'];
        $targetId = (int) $payload['target_lead_id'];

        $source = Lead::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->whereKey($sourceId)
            ->first();

        $target = Lead::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->whereKey($targetId)
            ->first();

        if (! $source instanceof Lead || ! $target instanceof Lead) {
            abort(422, 'Source or target lead does not belong to the active tenant.');
        }

        $approvalDecision = $approvalService->authorizeOrRequest(
            tenantId: $tenantId,
            actor: $user,
            action: 'lead.merge',
            subjectType: Lead::class,
            subjectId: $targetId,
            payload: [
                'source_lead_id' => $sourceId,
                'target_lead_id' => $targetId,
                'source_email' => $source->email,
                'target_email' => $target->email,
            ],
            approvalId: isset($payload['approval_id']) ? (int) $payload['approval_id'] : null,
            reason: $payload['approval_reason'] ?? null,
        );

        if (! ($approvalDecision['execute'] ?? false)) {
            return response()->json([
                'message' => 'Lead merge requires approval before execution.',
                'requires_approval' => true,
                'approval' => $approvalDecision['approval'] ?? null,
            ], 202);
        }

        $mergedLead = $this->performLeadMerge(
            tenantId: $tenantId,
            sourceLeadId: $sourceId,
            targetLeadId: $targetId,
            actorId: (int) $user->id,
        );

        $approval = $approvalDecision['approval'] ?? null;

        if ($approval instanceof HighRiskApproval) {
            $approvalService->markExecuted(
                approval: $approval,
                executedBy: (int) $user->id,
                executionMeta: [
                    'action' => 'lead.merge',
                    'source_lead_id' => $sourceId,
                    'target_lead_id' => $targetId,
                ],
            );
        }

        $eventService->emit(
            eventName: 'lead.updated',
            tenantId: $tenantId,
            subjectType: Lead::class,
            subjectId: $targetId,
            payload: [
                'source' => 'merge',
                'merged_from_id' => $sourceId,
            ],
        );

        return response()->json([
            'message' => 'Leads merged successfully.',
            'lead' => $mergedLead->load(['owner:id,name,email', 'team:id,name', 'tags:id,name,slug,color']),
            'merged_from_id' => $sourceId,
            'approval_id' => $approval instanceof HighRiskApproval ? (int) $approval->id : null,
        ]);
    }

    /**
     * Run bulk actions on selected leads.
     */
    public function bulk(Request $request, RealtimeEventService $eventService): JsonResponse
    {
        $this->authorizePermission($request, 'leads.update');

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

        DB::transaction(function () use ($request, $payload, $tenantId, $leads, $eventService): void {
            if ($payload['action'] === 'assign') {
                $this->applyBulkAssign($request, $tenantId, $leads, $payload);
            }

            if ($payload['action'] === 'tag') {
                $this->applyBulkTag($request, $tenantId, $leads, $payload);
            }

            if ($payload['action'] === 'status') {
                $this->applyBulkStatus($request, $leads, $payload, $eventService);
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
    public function import(
        Request $request,
        LeadImportService $leadImportService
    ): JsonResponse
    {
        $this->authorizePermission($request, 'leads.create');

        $tenantId = $this->resolveTenantIdForWrite($request);

        $payload = $request->validate([
            'auto_assign' => ['nullable', 'boolean'],
            'mapping_preset_id' => ['nullable', 'integer', 'exists:lead_import_presets,id'],
            'mapping' => ['nullable', 'array'],
            'defaults' => ['nullable', 'array'],
            'dedupe_policy' => ['nullable', Rule::in(['skip', 'update', 'merge'])],
            'dedupe_keys' => ['nullable', 'array'],
            'dedupe_keys.*' => [Rule::in(['email', 'phone'])],
            'leads' => ['required', 'array', 'min:1', 'max:1000'],
            'leads.*' => ['required', 'array'],
        ]);

        $preset = null;

        if (is_numeric($payload['mapping_preset_id'] ?? null)) {
            $preset = LeadImportPreset::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->whereKey((int) $payload['mapping_preset_id'])
                ->first();

            if (! $preset instanceof LeadImportPreset) {
                abort(422, 'Provided mapping_preset_id does not belong to the active tenant.');
            }
        }

        $presetMapping = is_array($preset?->mapping) ? $preset->mapping : [];
        $presetDefaults = is_array($preset?->defaults) ? $preset->defaults : [];
        $presetDedupeKeys = is_array($preset?->dedupe_keys) ? $preset->dedupe_keys : ['email', 'phone'];

        $result = $leadImportService->importRows($tenantId, $payload['leads'], [
            'auto_assign' => $payload['auto_assign'] ?? true,
            'mapping' => is_array($payload['mapping'] ?? null) ? array_replace_recursive($presetMapping, $payload['mapping']) : $presetMapping,
            'defaults' => is_array($payload['defaults'] ?? null) ? array_replace_recursive($presetDefaults, $payload['defaults']) : $presetDefaults,
            'dedupe_policy' => $payload['dedupe_policy'] ?? $preset?->dedupe_policy ?? 'skip',
            'dedupe_keys' => $payload['dedupe_keys'] ?? $presetDedupeKeys,
            'source' => 'import',
            'actor_id' => optional($request->user())->id,
            'import_meta' => [
                'preset_id' => $preset?->id,
            ],
        ]);

        if ($preset instanceof LeadImportPreset) {
            $preset->forceFill([
                'last_used_at' => now(),
                'updated_by' => optional($request->user())->id,
            ])->save();
        }

        return response()->json([
            'message' => 'Leads imported successfully.',
            'created_count' => (int) ($result['created_count'] ?? 0),
            'updated_count' => (int) ($result['updated_count'] ?? 0),
            'merged_count' => (int) ($result['merged_count'] ?? 0),
            'skipped_count' => (int) ($result['skipped_count'] ?? 0),
            'assigned_count' => (int) ($result['assigned_count'] ?? 0),
            'lead_ids' => $result['lead_ids'] ?? [],
            'affected_lead_ids' => $result['affected_lead_ids'] ?? [],
            'dedupe_policy' => $result['dedupe_policy'] ?? 'skip',
            'dedupe_keys' => $result['dedupe_keys'] ?? ['email', 'phone'],
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
            'country_code' => ['sometimes', 'nullable', 'string', 'max:8'],
            'interest' => ['sometimes', 'nullable', 'string', 'max:150'],
            'service' => ['sometimes', 'nullable', 'string', 'max:150'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'string', 'max:50'],
            'source' => ['sometimes', 'nullable', 'string', 'max:100'],
            'score' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'locale' => ['sometimes', 'nullable', 'string', 'max:12'],
            'team_id' => ['sometimes', 'nullable', 'integer', 'exists:teams,id'],
            'owner_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'email_consent' => ['sometimes', 'boolean'],
            'meta' => ['sometimes', 'nullable', 'array'],
            'settings' => ['sometimes', 'nullable', 'array'],
            'custom_fields' => ['sometimes', 'array'],
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
     * Merge source lead into target lead inside one transaction.
     */
    private function performLeadMerge(
        int $tenantId,
        int $sourceLeadId,
        int $targetLeadId,
        int $actorId
    ): Lead {
        return DB::transaction(function () use ($tenantId, $sourceLeadId, $targetLeadId, $actorId): Lead {
            $source = Lead::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->whereKey($sourceLeadId)
                ->lockForUpdate()
                ->first();

            $target = Lead::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->whereKey($targetLeadId)
                ->lockForUpdate()
                ->first();

            if (! $source instanceof Lead || ! $target instanceof Lead) {
                abort(422, 'Source or target lead could not be found for merge.');
            }

            if ((int) $source->id === (int) $target->id) {
                abort(422, 'Source and target leads must be different.');
            }

            $this->mergeLeadCoreAttributes($target, $source);
            $target->save();

            $sourceTagIds = $source->tags()
                ->pluck('tags.id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->unique()
                ->values();

            if ($sourceTagIds->isNotEmpty()) {
                $target->tags()->syncWithoutDetaching($sourceTagIds->mapWithKeys(
                    static fn (int $tagId): array => [$tagId => ['tenant_id' => $tenantId]]
                )->all());
            }

            DB::table('lead_tag')
                ->where('tenant_id', $tenantId)
                ->where('lead_id', $sourceLeadId)
                ->delete();

            $this->mergeLeadCustomFieldValues($tenantId, $sourceLeadId, $targetLeadId);
            $this->reassignLeadForeignKeys($tenantId, $sourceLeadId, $targetLeadId);

            $sourceMeta = is_array($source->meta) ? $source->meta : [];
            $sourceMeta['merged_into_lead_id'] = $targetLeadId;
            $sourceMeta['merged_at'] = now()->toIso8601String();

            $source->forceFill([
                'status' => 'merged',
                'meta' => $sourceMeta,
            ])->save();

            $source->delete();

            Activity::query()->withoutTenancy()->create([
                'tenant_id' => $tenantId,
                'actor_id' => $actorId,
                'type' => 'lead.admin.merged',
                'subject_type' => Lead::class,
                'subject_id' => $targetLeadId,
                'description' => 'Lead merged from admin module.',
                'properties' => [
                    'source_lead_id' => $sourceLeadId,
                    'target_lead_id' => $targetLeadId,
                ],
            ]);

            return $target->refresh();
        });
    }

    /**
     * Merge scalar + json attributes, preferring non-empty target values.
     */
    private function mergeLeadCoreAttributes(Lead $target, Lead $source): void
    {
        $scalarFields = [
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
            'locale',
            'source',
        ];

        foreach ($scalarFields as $field) {
            $targetValue = $target->getAttribute($field);
            $sourceValue = $source->getAttribute($field);

            if ($this->isBlankValue($targetValue) && ! $this->isBlankValue($sourceValue)) {
                $target->setAttribute($field, $sourceValue);
            }
        }

        if ($target->team_id === null && $source->team_id !== null) {
            $target->team_id = $source->team_id;
        }

        if ($target->owner_id === null && $source->owner_id !== null) {
            $target->owner_id = $source->owner_id;
        }

        $target->score = max((int) $target->score, (int) $source->score);

        if ($source->email_consent === false) {
            $target->email_consent = false;
        }

        if (
            $target->consent_updated_at === null
            || ($source->consent_updated_at !== null && $source->consent_updated_at->gt($target->consent_updated_at))
        ) {
            $target->consent_updated_at = $source->consent_updated_at;
        }

        $sourceMeta = is_array($source->meta) ? $source->meta : [];
        $targetMeta = is_array($target->meta) ? $target->meta : [];
        $target->meta = array_replace_recursive($sourceMeta, $targetMeta);

        $sourceSettings = is_array($source->settings) ? $source->settings : [];
        $targetSettings = is_array($target->settings) ? $target->settings : [];
        $target->settings = array_replace_recursive($sourceSettings, $targetSettings);
    }

    /**
     * Move lead-linked rows to merged target lead while avoiding duplicates.
     */
    private function reassignLeadForeignKeys(int $tenantId, int $sourceLeadId, int $targetLeadId): void
    {
        $timestamp = now();

        foreach (['messages', 'consent_events', 'lead_preferences', 'call_logs', 'ai_interactions'] as $table) {
            DB::table($table)
                ->where('tenant_id', $tenantId)
                ->where('lead_id', $sourceLeadId)
                ->update([
                    'lead_id' => $targetLeadId,
                    'updated_at' => $timestamp,
                ]);
        }

        DB::table('attachments')
            ->where('tenant_id', $tenantId)
            ->where('lead_id', $sourceLeadId)
            ->update([
                'lead_id' => $targetLeadId,
                'updated_at' => $timestamp,
            ]);

        DB::table('attachments')
            ->where('tenant_id', $tenantId)
            ->where('entity_type', 'lead')
            ->where('entity_id', $sourceLeadId)
            ->update([
                'lead_id' => $targetLeadId,
                'entity_id' => $targetLeadId,
                'updated_at' => $timestamp,
            ]);

        DB::table('activities')
            ->where('tenant_id', $tenantId)
            ->where('subject_type', Lead::class)
            ->where('subject_id', $sourceLeadId)
            ->update([
                'subject_id' => $targetLeadId,
                'updated_at' => $timestamp,
            ]);

        $sourceUnsubscribes = DB::table('unsubscribes')
            ->where('tenant_id', $tenantId)
            ->where('lead_id', $sourceLeadId)
            ->get(['id', 'channel', 'value']);

        foreach ($sourceUnsubscribes as $row) {
            $duplicateExists = DB::table('unsubscribes')
                ->where('tenant_id', $tenantId)
                ->where('channel', $row->channel)
                ->where('value', $row->value)
                ->where('id', '!=', $row->id)
                ->exists();

            if ($duplicateExists) {
                DB::table('unsubscribes')
                    ->where('id', $row->id)
                    ->delete();

                continue;
            }

            DB::table('unsubscribes')
                ->where('id', $row->id)
                ->update([
                    'lead_id' => $targetLeadId,
                    'updated_at' => $timestamp,
                ]);
        }
    }

    /**
     * Merge custom field values without violating unique field constraints.
     */
    private function mergeLeadCustomFieldValues(int $tenantId, int $sourceLeadId, int $targetLeadId): void
    {
        $timestamp = now();

        $sourceValues = DB::table('lead_custom_field_values')
            ->where('tenant_id', $tenantId)
            ->where('lead_id', $sourceLeadId)
            ->orderBy('id')
            ->get(['id', 'custom_field_id', 'value']);

        foreach ($sourceValues as $sourceValue) {
            $targetValue = DB::table('lead_custom_field_values')
                ->where('tenant_id', $tenantId)
                ->where('lead_id', $targetLeadId)
                ->where('custom_field_id', $sourceValue->custom_field_id)
                ->first(['id', 'value']);

            if ($targetValue === null) {
                DB::table('lead_custom_field_values')
                    ->where('id', $sourceValue->id)
                    ->update([
                        'lead_id' => $targetLeadId,
                        'updated_at' => $timestamp,
                    ]);

                continue;
            }

            $targetHasValue = $targetValue->value !== null && $targetValue->value !== 'null';
            $sourceHasValue = $sourceValue->value !== null && $sourceValue->value !== 'null';

            if (! $targetHasValue && $sourceHasValue) {
                DB::table('lead_custom_field_values')
                    ->where('id', $targetValue->id)
                    ->update([
                        'value' => $sourceValue->value,
                        'updated_at' => $timestamp,
                    ]);
            }

            DB::table('lead_custom_field_values')
                ->where('id', $sourceValue->id)
                ->delete();
        }
    }

    private function isBlankValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        return false;
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
    private function applyBulkStatus(
        Request $request,
        Collection $leads,
        array $payload,
        RealtimeEventService $eventService
    ): void
    {
        if (! isset($payload['status'])) {
            abort(422, 'status is required for status bulk action.');
        }

        foreach ($leads as $lead) {
            $previousStatus = (string) $lead->status;
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

            if ($previousStatus !== (string) $payload['status']) {
                $eventService->emit(
                    eventName: 'deal.stage_changed',
                    tenantId: (int) $lead->tenant_id,
                    subjectType: Lead::class,
                    subjectId: (int) $lead->id,
                    payload: [
                        'from' => $previousStatus,
                        'to' => (string) $payload['status'],
                    ],
                );
            }
        }
    }
}
