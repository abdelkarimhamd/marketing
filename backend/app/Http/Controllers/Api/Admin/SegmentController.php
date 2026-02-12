<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Segment;
use App\Services\SegmentEvaluationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SegmentController extends Controller
{
    /**
     * Display paginated segments.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Segment::query();

        if (! empty($filters['search'])) {
            $search = $filters['search'];

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

        $segments = $query
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->withQueryString();

        return response()->json($segments);
    }

    /**
     * Store a new segment.
     */
    public function store(Request $request, SegmentEvaluationService $segmentService): JsonResponse
    {
        $this->authorizeAdmin($request);

        $tenantId = $this->resolveTenantIdForWrite($request);
        $payload = $this->validatePayload($request, $tenantId, isUpdate: false);

        $segmentService->validateRules($payload['rules_json'] ?? null);

        $segment = Segment::query()->withoutTenancy()->create([
            'tenant_id' => $tenantId,
            'name' => $payload['name'],
            'slug' => $payload['slug'] ?? Str::slug($payload['name']),
            'description' => $payload['description'] ?? null,
            'rules_json' => $payload['rules_json'] ?? null,
            'filters' => $payload['rules_json'] ?? null,
            'settings' => $payload['settings'] ?? [],
            'is_active' => $payload['is_active'] ?? true,
        ]);

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => $tenantId,
            'actor_id' => optional($request->user())->id,
            'type' => 'segment.admin.created',
            'subject_type' => Segment::class,
            'subject_id' => $segment->id,
            'description' => 'Segment created from admin module.',
        ]);

        return response()->json([
            'message' => 'Segment created successfully.',
            'segment' => $segment,
        ], 201);
    }

    /**
     * Display a segment.
     */
    public function show(Request $request, Segment $segment): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json([
            'segment' => $segment,
        ]);
    }

    /**
     * Update a segment.
     */
    public function update(
        Request $request,
        Segment $segment,
        SegmentEvaluationService $segmentService
    ): JsonResponse {
        $this->authorizeAdmin($request);

        $payload = $this->validatePayload($request, (int) $segment->tenant_id, true, $segment);

        $rulesJson = array_key_exists('rules_json', $payload)
            ? $payload['rules_json']
            : $segment->rules_json;

        $segmentService->validateRules(is_array($rulesJson) ? $rulesJson : null);

        $segment->fill([
            'name' => $payload['name'] ?? $segment->name,
            'slug' => $payload['slug'] ?? $segment->slug,
            'description' => $payload['description'] ?? $segment->description,
            'rules_json' => $rulesJson,
            'filters' => $rulesJson,
            'settings' => $payload['settings'] ?? $segment->settings,
            'is_active' => $payload['is_active'] ?? $segment->is_active,
        ]);

        $segment->save();

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => $segment->tenant_id,
            'actor_id' => optional($request->user())->id,
            'type' => 'segment.admin.updated',
            'subject_type' => Segment::class,
            'subject_id' => $segment->id,
            'description' => 'Segment updated from admin module.',
        ]);

        return response()->json([
            'message' => 'Segment updated successfully.',
            'segment' => $segment->refresh(),
        ]);
    }

    /**
     * Delete a segment.
     */
    public function destroy(Request $request, Segment $segment): JsonResponse
    {
        $this->authorizeAdmin($request);

        $segment->delete();

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => $segment->tenant_id,
            'actor_id' => optional($request->user())->id,
            'type' => 'segment.admin.deleted',
            'subject_type' => Segment::class,
            'subject_id' => $segment->id,
            'description' => 'Segment deleted from admin module.',
        ]);

        return response()->json([
            'message' => 'Segment deleted successfully.',
        ]);
    }

    /**
     * Evaluate segment rules and return matching lead preview.
     */
    public function preview(
        Request $request,
        Segment $segment,
        SegmentEvaluationService $segmentService
    ): JsonResponse {
        $this->authorizeAdmin($request);

        $payload = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'include_rows' => ['nullable', 'boolean'],
        ]);

        $query = $segmentService
            ->queryForSegment($segment)
            ->with(['owner:id,name,email', 'team:id,name']);

        $total = (clone $query)->count();

        $response = [
            'segment_id' => $segment->id,
            'matched_count' => $total,
        ];

        if ((bool) ($payload['include_rows'] ?? true)) {
            $leads = $query
                ->orderByDesc('id')
                ->paginate((int) ($payload['per_page'] ?? 15))
                ->withQueryString();

            $response['leads'] = $leads;
        }

        return response()->json($response);
    }

    /**
     * Validate segment payload.
     *
     * @return array<string, mixed>
     */
    private function validatePayload(
        Request $request,
        int $tenantId,
        bool $isUpdate = false,
        ?Segment $segment = null
    ): array {
        $slugRule = Rule::unique('segments', 'slug')
            ->where(fn ($builder) => $builder->where('tenant_id', $tenantId));

        if ($segment !== null) {
            $slugRule->ignore($segment->id);
        }

        $rules = [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', $slugRule],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'rules_json' => ['sometimes', 'nullable', 'array'],
            'settings' => ['sometimes', 'nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        if (! $isUpdate) {
            $rules['name'][] = 'required';
        }

        return $request->validate($rules);
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
