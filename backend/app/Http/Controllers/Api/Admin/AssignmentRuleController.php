<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AssignmentRule;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssignmentRuleController extends Controller
{
    /**
     * Display assignment rules.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'assignment_rules.view');

        $rules = AssignmentRule::query()
            ->with(['team:id,name', 'fallbackOwner:id,name,email', 'lastAssignedUser:id,name,email'])
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        return response()->json([
            'rules' => $rules->map(fn (AssignmentRule $rule): array => $this->serializeRule($rule))->values(),
        ]);
    }

    /**
     * Store a new assignment rule.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'assignment_rules.create');

        $tenantId = $this->resolveTenantIdForWrite($request);
        $payload = $this->validatePayload($request);
        $this->validateTenantReferences($tenantId, $payload);

        $rule = AssignmentRule::query()->withoutTenancy()->create([
            'tenant_id' => $tenantId,
            'team_id' => $payload['team_id'] ?? null,
            'fallback_owner_id' => $payload['fallback_owner_id'] ?? null,
            'name' => $payload['name'],
            'is_active' => $payload['is_active'] ?? true,
            'priority' => $payload['priority'] ?? 100,
            'strategy' => $payload['strategy'],
            'auto_assign_on_intake' => $payload['auto_assign_on_intake'] ?? true,
            'auto_assign_on_import' => $payload['auto_assign_on_import'] ?? true,
            'conditions' => $payload['conditions'] ?? [],
            'settings' => $this->mergeSettingsWithActions($payload, []),
        ]);

        return response()->json([
            'message' => 'Assignment rule created successfully.',
            'rule' => $this->serializeRule(
                $rule->load(['team:id,name', 'fallbackOwner:id,name,email', 'lastAssignedUser:id,name,email'])
            ),
        ], 201);
    }

    /**
     * Show one assignment rule.
     */
    public function show(Request $request, AssignmentRule $assignmentRule): JsonResponse
    {
        $this->authorizePermission($request, 'assignment_rules.view');

        return response()->json([
            'rule' => $this->serializeRule(
                $assignmentRule->load(['team:id,name', 'fallbackOwner:id,name,email', 'lastAssignedUser:id,name,email'])
            ),
        ]);
    }

    /**
     * Update an assignment rule.
     */
    public function update(Request $request, AssignmentRule $assignmentRule): JsonResponse
    {
        $this->authorizePermission($request, 'assignment_rules.update');

        $payload = $this->validatePayload($request, isUpdate: true);
        $this->validateTenantReferences((int) $assignmentRule->tenant_id, $payload);

        $assignmentRule->fill([
            'team_id' => array_key_exists('team_id', $payload) ? $payload['team_id'] : $assignmentRule->team_id,
            'fallback_owner_id' => array_key_exists('fallback_owner_id', $payload) ? $payload['fallback_owner_id'] : $assignmentRule->fallback_owner_id,
            'name' => $payload['name'] ?? $assignmentRule->name,
            'is_active' => $payload['is_active'] ?? $assignmentRule->is_active,
            'priority' => $payload['priority'] ?? $assignmentRule->priority,
            'strategy' => $payload['strategy'] ?? $assignmentRule->strategy,
            'auto_assign_on_intake' => $payload['auto_assign_on_intake'] ?? $assignmentRule->auto_assign_on_intake,
            'auto_assign_on_import' => $payload['auto_assign_on_import'] ?? $assignmentRule->auto_assign_on_import,
            'conditions' => $payload['conditions'] ?? $assignmentRule->conditions,
            'settings' => $this->mergeSettingsWithActions($payload, is_array($assignmentRule->settings) ? $assignmentRule->settings : []),
        ]);

        $assignmentRule->save();

        return response()->json([
            'message' => 'Assignment rule updated successfully.',
            'rule' => $this->serializeRule(
                $assignmentRule->refresh()->load(['team:id,name', 'fallbackOwner:id,name,email', 'lastAssignedUser:id,name,email'])
            ),
        ]);
    }

    /**
     * Delete an assignment rule.
     */
    public function destroy(Request $request, AssignmentRule $assignmentRule): JsonResponse
    {
        $this->authorizePermission($request, 'assignment_rules.delete');

        $assignmentRule->delete();

        return response()->json([
            'message' => 'Assignment rule deleted successfully.',
        ]);
    }

    /**
     * Validate payload for rule create/update.
     *
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, bool $isUpdate = false): array
    {
        $rules = [
            'name' => ['sometimes', 'string', 'max:255'],
            'team_id' => ['sometimes', 'nullable', 'integer', 'exists:teams,id'],
            'fallback_owner_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'is_active' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'strategy' => ['sometimes', Rule::in(AssignmentRule::supportedStrategies())],
            'auto_assign_on_intake' => ['sometimes', 'boolean'],
            'auto_assign_on_import' => ['sometimes', 'boolean'],
            'conditions' => ['sometimes', 'array'],
            'settings' => ['sometimes', 'array'],
            'actions' => ['sometimes', 'array', 'max:20'],
            'actions.*.type' => ['required_with:actions', Rule::in(AssignmentRule::supportedActionTypes())],
            'actions.*.mode' => ['sometimes', 'string', 'max:50'],
            'actions.*.owner_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'actions.*.team_id' => ['sometimes', 'nullable', 'integer', 'exists:teams,id'],
            'actions.*.fallback_owner_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'actions.*.force_reassign' => ['sometimes', 'boolean'],
            'actions.*.reassign_if_unavailable' => ['sometimes', 'boolean'],
            'actions.*.allow_unavailable_owner' => ['sometimes', 'boolean'],
            'actions.*.allow_unavailable_fallback' => ['sometimes', 'boolean'],
            'actions.*.offline_after_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10080'],
            'actions.*.max_active_leads' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'actions.*.status' => ['sometimes', 'string', 'max:50'],
            'actions.*.pipeline' => ['sometimes', 'string', 'max:120'],
            'actions.*.stage' => ['sometimes', 'string', 'max:120'],
            'actions.*.title' => ['sometimes', 'string', 'max:255'],
            'actions.*.tags' => ['sometimes', 'array', 'max:100'],
            'actions.*.tags.*' => ['string', 'max:80'],
            'actions.*.tag_names' => ['sometimes', 'array', 'max:100'],
            'actions.*.tag_names.*' => ['string', 'max:80'],
            'actions.*.tag_ids' => ['sometimes', 'array', 'max:100'],
            'actions.*.tag_ids.*' => ['integer', 'exists:tags,id'],
            'actions.*.automation' => ['sometimes', 'string', 'max:120'],
            'actions.*.workflow' => ['sometimes', 'string', 'max:120'],
            'actions.*.channel' => ['sometimes', 'string', 'max:120'],
            'actions.*.message' => ['sometimes', 'string', 'max:1000'],
            'actions.*.payload' => ['sometimes', 'array'],
            'actions.*.enabled' => ['sometimes', 'boolean'],
            'actions.*.stop_processing' => ['sometimes', 'boolean'],
        ];

        if (! $isUpdate) {
            $rules['name'][] = 'required';
            $rules['strategy'][] = 'required';
        }

        return $request->validate($rules);
    }

    /**
     * Ensure referenced entities belong to the same tenant.
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

        if (array_key_exists('fallback_owner_id', $payload) && $payload['fallback_owner_id'] !== null) {
            $ownerExists = User::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->whereKey($payload['fallback_owner_id'])
                ->exists();

            if (! $ownerExists) {
                abort(422, 'Provided fallback_owner_id does not belong to the active tenant.');
            }
        }

        $actions = is_array($payload['actions'] ?? null) ? $payload['actions'] : [];

        foreach ($actions as $index => $action) {
            if (! is_array($action)) {
                continue;
            }

            foreach (['owner_id', 'fallback_owner_id'] as $ownerKey) {
                if (! array_key_exists($ownerKey, $action) || $action[$ownerKey] === null) {
                    continue;
                }

                $ownerExists = User::query()
                    ->withoutTenancy()
                    ->where('tenant_id', $tenantId)
                    ->whereKey((int) $action[$ownerKey])
                    ->exists();

                if (! $ownerExists) {
                    abort(422, sprintf('Action %d has %s outside active tenant.', $index, $ownerKey));
                }
            }

            if (array_key_exists('team_id', $action) && $action['team_id'] !== null) {
                $teamExists = Team::query()
                    ->withoutTenancy()
                    ->where('tenant_id', $tenantId)
                    ->whereKey((int) $action['team_id'])
                    ->exists();

                if (! $teamExists) {
                    abort(422, sprintf('Action %d has team_id outside active tenant.', $index));
                }
            }

            $tagIds = is_array($action['tag_ids'] ?? null) ? $action['tag_ids'] : [];

            if ($tagIds !== []) {
                $validTagIds = Tag::query()
                    ->withoutTenancy()
                    ->where('tenant_id', $tenantId)
                    ->whereIn('id', collect($tagIds)->map(static fn (mixed $id): int => (int) $id))
                    ->pluck('id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all();

                $providedTagIds = collect($tagIds)
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->filter(static fn (int $id): bool => $id > 0)
                    ->unique()
                    ->values()
                    ->all();

                if (array_diff($providedTagIds, $validTagIds) !== []) {
                    abort(422, sprintf('Action %d has tag_ids outside active tenant.', $index));
                }
            }
        }
    }

    /**
     * Serialize one rule with top-level actions for clients.
     *
     * @return array<string, mixed>
     */
    private function serializeRule(AssignmentRule $rule): array
    {
        $data = $rule->toArray();
        $settings = is_array($rule->settings) ? $rule->settings : [];
        $actions = is_array($settings['actions'] ?? null) ? $settings['actions'] : [];

        $data['settings'] = $settings;
        $data['actions'] = array_values(array_filter($actions, static fn (mixed $action): bool => is_array($action)));

        return $data;
    }

    /**
     * Merge incoming settings + actions payload into one settings object.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $existingSettings
     * @return array<string, mixed>
     */
    private function mergeSettingsWithActions(array $payload, array $existingSettings): array
    {
        $settings = array_merge(
            $existingSettings,
            is_array($payload['settings'] ?? null) ? $payload['settings'] : []
        );

        if (array_key_exists('actions', $payload)) {
            $settings['actions'] = array_values(
                array_filter(
                    is_array($payload['actions']) ? $payload['actions'] : [],
                    static fn (mixed $action): bool => is_array($action)
                )
            );
        }

        return $settings;
    }

    /**
     * Resolve tenant id for write operations.
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

}
