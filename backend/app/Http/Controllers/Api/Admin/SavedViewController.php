<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SavedView;
use App\Models\Team;
use App\Models\TeamUser;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class SavedViewController extends Controller
{
    /**
     * List saved views visible to the current user.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'leads.view');
        [$tenantId, $user] = $this->resolveContext($request);
        $teamIds = $this->accessibleTeamIds($tenantId, $user);

        $filters = $request->validate([
            'entity' => ['nullable', 'string', 'max:32'],
            'scope' => ['nullable', 'string', Rule::in(['user', 'team'])],
        ]);

        $entity = $this->resolveEntity($filters['entity'] ?? 'global_search');

        $query = SavedView::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('entity', $entity)
            ->where(function ($builder) use ($user, $teamIds): void {
                $builder->where(function ($userScope) use ($user): void {
                    $userScope
                        ->where('scope', 'user')
                        ->where('user_id', $user->id);
                });

                if ($teamIds->isNotEmpty()) {
                    $builder->orWhere(function ($teamScope) use ($teamIds): void {
                        $teamScope
                            ->where('scope', 'team')
                            ->whereIn('team_id', $teamIds->all());
                    });
                }
            });

        if (! empty($filters['scope'])) {
            $query->where('scope', $filters['scope']);
        }

        $savedViews = $query
            ->with(['user:id,name,email', 'team:id,name'])
            ->orderBy('scope')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $savedViews
                ->map(fn (SavedView $savedView): array => $this->presentSavedView($savedView, $user))
                ->values(),
        ]);
    }

    /**
     * Create a saved view for user or team scope.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'leads.view');
        [$tenantId, $user] = $this->resolveContext($request);

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scope' => ['nullable', 'string', Rule::in(['user', 'team'])],
            'team_id' => ['nullable', 'integer'],
            'entity' => ['nullable', 'string', 'max:32'],
            'query' => ['nullable', 'string', 'max:255'],
            'filters' => ['nullable', 'array'],
            'settings' => ['nullable', 'array'],
        ]);

        $scope = (string) ($payload['scope'] ?? 'user');
        $teamId = $this->resolveScopedTeamId($tenantId, $user, $scope, $payload['team_id'] ?? null);

        $savedView = SavedView::query()
            ->withoutTenancy()
            ->create([
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'team_id' => $teamId,
                'name' => trim((string) $payload['name']),
                'scope' => $scope,
                'entity' => $this->resolveEntity($payload['entity'] ?? 'global_search'),
                'query' => $this->normalizeNullableString($payload['query'] ?? null),
                'filters' => is_array($payload['filters'] ?? null) ? $payload['filters'] : [],
                'settings' => is_array($payload['settings'] ?? null) ? $payload['settings'] : [],
            ]);

        return response()->json([
            'message' => 'Saved view created successfully.',
            'saved_view' => $this->presentSavedView(
                $savedView->load(['user:id,name,email', 'team:id,name']),
                $user
            ),
        ], 201);
    }

    /**
     * Update one saved view.
     */
    public function update(Request $request, SavedView $savedView): JsonResponse
    {
        $this->authorizePermission($request, 'leads.view');
        [$tenantId, $user] = $this->resolveContext($request);

        $this->ensureViewAccess($savedView, $tenantId, $user);

        if (! $this->canEdit($savedView, $user)) {
            abort(403, 'You are not allowed to edit this saved view.');
        }

        $payload = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'scope' => ['sometimes', 'string', Rule::in(['user', 'team'])],
            'team_id' => ['sometimes', 'nullable', 'integer'],
            'entity' => ['sometimes', 'string', 'max:32'],
            'query' => ['sometimes', 'nullable', 'string', 'max:255'],
            'filters' => ['sometimes', 'array'],
            'settings' => ['sometimes', 'array'],
        ]);

        $scope = (string) ($payload['scope'] ?? $savedView->scope ?? 'user');
        $teamId = $this->resolveScopedTeamId(
            tenantId: $tenantId,
            user: $user,
            scope: $scope,
            teamId: array_key_exists('team_id', $payload) ? $payload['team_id'] : $savedView->team_id,
        );

        $savedView->forceFill([
            'name' => array_key_exists('name', $payload)
                ? trim((string) $payload['name'])
                : $savedView->name,
            'scope' => $scope,
            'team_id' => $teamId,
            'entity' => array_key_exists('entity', $payload)
                ? $this->resolveEntity($payload['entity'])
                : $this->resolveEntity($savedView->entity),
            'query' => array_key_exists('query', $payload)
                ? $this->normalizeNullableString($payload['query'])
                : $savedView->query,
            'filters' => array_key_exists('filters', $payload)
                ? (is_array($payload['filters']) ? $payload['filters'] : [])
                : $savedView->filters,
            'settings' => array_key_exists('settings', $payload)
                ? (is_array($payload['settings']) ? $payload['settings'] : [])
                : $savedView->settings,
        ])->save();

        return response()->json([
            'message' => 'Saved view updated successfully.',
            'saved_view' => $this->presentSavedView(
                $savedView->refresh()->load(['user:id,name,email', 'team:id,name']),
                $user
            ),
        ]);
    }

    /**
     * Delete one saved view.
     */
    public function destroy(Request $request, SavedView $savedView): JsonResponse
    {
        $this->authorizePermission($request, 'leads.view');
        [$tenantId, $user] = $this->resolveContext($request);

        $this->ensureViewAccess($savedView, $tenantId, $user);

        if (! $this->canEdit($savedView, $user)) {
            abort(403, 'You are not allowed to delete this saved view.');
        }

        $savedView->delete();

        return response()->json([
            'message' => 'Saved view deleted successfully.',
        ]);
    }

    /**
     * Ensure view belongs to tenant and is visible to user.
     */
    private function ensureViewAccess(SavedView $savedView, int $tenantId, User $user): void
    {
        if ((int) $savedView->tenant_id !== $tenantId) {
            abort(403, 'Saved view does not belong to active tenant context.');
        }

        if (! $this->canView($savedView, $tenantId, $user)) {
            abort(403, 'You are not allowed to access this saved view.');
        }
    }

    /**
     * Determine if user can view one saved view.
     */
    private function canView(SavedView $savedView, int $tenantId, User $user): bool
    {
        if ($savedView->scope === 'user') {
            return (int) $savedView->user_id === (int) $user->id;
        }

        if ($savedView->scope === 'team' && $savedView->team_id !== null) {
            return $this->accessibleTeamIds($tenantId, $user)
                ->contains((int) $savedView->team_id);
        }

        return false;
    }

    /**
     * Determine if user can edit/delete one saved view.
     */
    private function canEdit(SavedView $savedView, User $user): bool
    {
        if ($user->isSuperAdmin() || $user->isTenantAdmin()) {
            return true;
        }

        return (int) $savedView->user_id === (int) $user->id;
    }

    /**
     * Resolve scope team id and validate access.
     */
    private function resolveScopedTeamId(int $tenantId, User $user, string $scope, mixed $teamId): ?int
    {
        if ($scope !== 'team') {
            return null;
        }

        $resolvedTeamId = is_numeric($teamId) ? (int) $teamId : 0;

        if ($resolvedTeamId <= 0) {
            abort(422, 'team_id is required for team scope.');
        }

        $exists = Team::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->whereKey($resolvedTeamId)
            ->exists();

        if (! $exists) {
            abort(422, 'team_id does not belong to this tenant.');
        }

        if (! $this->canAccessTeam($tenantId, $user, $resolvedTeamId)) {
            abort(403, 'You do not have access to this team.');
        }

        return $resolvedTeamId;
    }

    /**
     * Determine if user can access a team.
     */
    private function canAccessTeam(int $tenantId, User $user, int $teamId): bool
    {
        if ($user->isSuperAdmin() || $user->isTenantAdmin()) {
            return true;
        }

        return TeamUser::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('team_id', $teamId)
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * Resolve list of teams visible to user.
     *
     * @return Collection<int, int>
     */
    private function accessibleTeamIds(int $tenantId, User $user): Collection
    {
        if ($user->isSuperAdmin() || $user->isTenantAdmin()) {
            return Team::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id);
        }

        return TeamUser::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->pluck('team_id')
            ->map(static fn (mixed $id): int => (int) $id);
    }

    /**
     * Resolve tenant and authenticated user.
     *
     * @return array{int, User}
     */
    private function resolveContext(Request $request): array
    {
        $tenantId = $this->resolveTenantIdForPermission($request, $request->user());

        if ($tenantId === null || ! Tenant::query()->whereKey($tenantId)->exists()) {
            abort(422, 'Tenant context is required.');
        }

        $user = $request->user();

        if (! $user instanceof User) {
            abort(401, 'Authentication is required.');
        }

        return [(int) $tenantId, $user];
    }

    /**
     * Render one saved view payload for API responses.
     *
     * @return array<string, mixed>
     */
    private function presentSavedView(SavedView $savedView, User $user): array
    {
        return [
            'id' => (int) $savedView->id,
            'tenant_id' => (int) $savedView->tenant_id,
            'user_id' => $savedView->user_id !== null ? (int) $savedView->user_id : null,
            'team_id' => $savedView->team_id !== null ? (int) $savedView->team_id : null,
            'name' => $savedView->name,
            'scope' => $savedView->scope,
            'entity' => $savedView->entity,
            'query' => $savedView->query,
            'filters' => is_array($savedView->filters) ? $savedView->filters : [],
            'settings' => is_array($savedView->settings) ? $savedView->settings : [],
            'can_edit' => $this->canEdit($savedView, $user),
            'user' => $savedView->relationLoaded('user') && $savedView->user !== null ? [
                'id' => (int) $savedView->user->id,
                'name' => $savedView->user->name,
                'email' => $savedView->user->email,
            ] : null,
            'team' => $savedView->relationLoaded('team') && $savedView->team !== null ? [
                'id' => (int) $savedView->team->id,
                'name' => $savedView->team->name,
            ] : null,
            'updated_at' => optional($savedView->updated_at)?->toISOString(),
            'created_at' => optional($savedView->created_at)?->toISOString(),
        ];
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function resolveEntity(mixed $value): string
    {
        $entity = trim((string) $value);

        return $entity !== '' ? $entity : 'global_search';
    }
}
