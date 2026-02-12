<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AssignmentRule;
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
        $this->authorizeAdmin($request);

        $rules = AssignmentRule::query()
            ->with(['team:id,name', 'fallbackOwner:id,name,email', 'lastAssignedUser:id,name,email'])
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        return response()->json([
            'rules' => $rules,
        ]);
    }

    /**
     * Store a new assignment rule.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

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
            'settings' => $payload['settings'] ?? [],
        ]);

        return response()->json([
            'message' => 'Assignment rule created successfully.',
            'rule' => $rule->load(['team:id,name', 'fallbackOwner:id,name,email', 'lastAssignedUser:id,name,email']),
        ], 201);
    }

    /**
     * Show one assignment rule.
     */
    public function show(Request $request, AssignmentRule $assignmentRule): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json([
            'rule' => $assignmentRule->load(['team:id,name', 'fallbackOwner:id,name,email', 'lastAssignedUser:id,name,email']),
        ]);
    }

    /**
     * Update an assignment rule.
     */
    public function update(Request $request, AssignmentRule $assignmentRule): JsonResponse
    {
        $this->authorizeAdmin($request);

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
            'settings' => $payload['settings'] ?? $assignmentRule->settings,
        ]);

        $assignmentRule->save();

        return response()->json([
            'message' => 'Assignment rule updated successfully.',
            'rule' => $assignmentRule->refresh()->load(['team:id,name', 'fallbackOwner:id,name,email', 'lastAssignedUser:id,name,email']),
        ]);
    }

    /**
     * Delete an assignment rule.
     */
    public function destroy(Request $request, AssignmentRule $assignmentRule): JsonResponse
    {
        $this->authorizeAdmin($request);

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
            'strategy' => ['sometimes', Rule::in([
                AssignmentRule::STRATEGY_ROUND_ROBIN,
                AssignmentRule::STRATEGY_CITY,
                AssignmentRule::STRATEGY_INTEREST_SERVICE,
            ])],
            'auto_assign_on_intake' => ['sometimes', 'boolean'],
            'auto_assign_on_import' => ['sometimes', 'boolean'],
            'conditions' => ['sometimes', 'array'],
            'settings' => ['sometimes', 'array'],
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
}
