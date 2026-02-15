<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Brand;
use App\Models\ProposalTemplate;
use App\Models\Tenant;
use App\Services\RealtimeEventService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProposalTemplateController extends Controller
{
    /**
     * List proposal templates for active tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'templates.view');

        $tenantId = $this->resolveTenantIdStrict($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'service' => ['nullable', 'string', 'max:120'],
            'brand_id' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = ProposalTemplate::query()
            ->withoutTenancy()
            ->with('brand:id,name,slug')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id');

        if (is_string($filters['search'] ?? null) && trim((string) $filters['search']) !== '') {
            $search = trim((string) $filters['search']);
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('service', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%");
            });
        }

        if (is_string($filters['service'] ?? null) && trim((string) $filters['service']) !== '') {
            $query->where('service', trim((string) $filters['service']));
        }

        if (is_numeric($filters['brand_id'] ?? null)) {
            $query->where('brand_id', (int) $filters['brand_id']);
        }

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        $templates = $query
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->withQueryString();

        return response()->json($templates);
    }

    /**
     * Create proposal template.
     */
    public function store(Request $request, RealtimeEventService $eventService): JsonResponse
    {
        $this->authorizePermission($request, 'templates.create');

        $tenantId = $this->resolveTenantIdStrict($request);
        $payload = $this->validatePayload($request, $tenantId);

        $template = ProposalTemplate::query()->withoutTenancy()->create([
            'tenant_id' => $tenantId,
            'brand_id' => $payload['brand_id'] ?? null,
            'name' => trim((string) $payload['name']),
            'slug' => $payload['slug'] ?? Str::slug((string) $payload['name']),
            'service' => $payload['service'] ?? null,
            'currency' => $payload['currency'] ?? null,
            'subject' => $payload['subject'] ?? null,
            'body_html' => (string) $payload['body_html'],
            'body_text' => $payload['body_text'] ?? null,
            'settings' => is_array($payload['settings'] ?? null) ? $payload['settings'] : [],
            'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true,
        ]);

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => $tenantId,
            'actor_id' => $request->user()?->id,
            'type' => 'proposal.template.created',
            'subject_type' => ProposalTemplate::class,
            'subject_id' => (int) $template->id,
            'description' => 'Proposal template created from admin module.',
        ]);

        $eventService->emit(
            eventName: 'proposal.template.created',
            tenantId: $tenantId,
            subjectType: ProposalTemplate::class,
            subjectId: (int) $template->id,
            payload: [
                'service' => $template->service,
            ],
        );

        return response()->json([
            'message' => 'Proposal template created successfully.',
            'template' => $template->load('brand:id,name,slug'),
        ], 201);
    }

    /**
     * Show one proposal template.
     */
    public function show(Request $request, ProposalTemplate $proposalTemplate): JsonResponse
    {
        $this->authorizePermission($request, 'templates.view');

        $tenantId = $this->resolveTenantIdStrict($request);

        if ((int) $proposalTemplate->tenant_id !== $tenantId) {
            abort(404, 'Proposal template not found.');
        }

        return response()->json([
            'template' => $proposalTemplate->load('brand:id,name,slug'),
        ]);
    }

    /**
     * Update one proposal template.
     */
    public function update(
        Request $request,
        ProposalTemplate $proposalTemplate,
        RealtimeEventService $eventService,
    ): JsonResponse {
        $this->authorizePermission($request, 'templates.update');

        $tenantId = $this->resolveTenantIdStrict($request);

        if ((int) $proposalTemplate->tenant_id !== $tenantId) {
            abort(404, 'Proposal template not found.');
        }

        $payload = $this->validatePayload($request, $tenantId, true, $proposalTemplate);

        $proposalTemplate->fill([
            'brand_id' => array_key_exists('brand_id', $payload) ? $payload['brand_id'] : $proposalTemplate->brand_id,
            'name' => array_key_exists('name', $payload) ? trim((string) $payload['name']) : $proposalTemplate->name,
            'slug' => $payload['slug'] ?? $proposalTemplate->slug,
            'service' => array_key_exists('service', $payload) ? $payload['service'] : $proposalTemplate->service,
            'currency' => array_key_exists('currency', $payload) ? $payload['currency'] : $proposalTemplate->currency,
            'subject' => array_key_exists('subject', $payload) ? $payload['subject'] : $proposalTemplate->subject,
            'body_html' => array_key_exists('body_html', $payload) ? (string) $payload['body_html'] : $proposalTemplate->body_html,
            'body_text' => array_key_exists('body_text', $payload) ? $payload['body_text'] : $proposalTemplate->body_text,
            'settings' => array_key_exists('settings', $payload)
                ? (is_array($payload['settings']) ? $payload['settings'] : [])
                : $proposalTemplate->settings,
            'is_active' => array_key_exists('is_active', $payload)
                ? (bool) $payload['is_active']
                : $proposalTemplate->is_active,
        ]);

        $proposalTemplate->save();

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => $tenantId,
            'actor_id' => $request->user()?->id,
            'type' => 'proposal.template.updated',
            'subject_type' => ProposalTemplate::class,
            'subject_id' => (int) $proposalTemplate->id,
            'description' => 'Proposal template updated from admin module.',
        ]);

        $eventService->emit(
            eventName: 'proposal.template.updated',
            tenantId: $tenantId,
            subjectType: ProposalTemplate::class,
            subjectId: (int) $proposalTemplate->id,
            payload: [
                'service' => $proposalTemplate->service,
            ],
        );

        return response()->json([
            'message' => 'Proposal template updated successfully.',
            'template' => $proposalTemplate->refresh()->load('brand:id,name,slug'),
        ]);
    }

    /**
     * Delete one proposal template.
     */
    public function destroy(
        Request $request,
        ProposalTemplate $proposalTemplate,
        RealtimeEventService $eventService,
    ): JsonResponse {
        $this->authorizePermission($request, 'templates.delete');

        $tenantId = $this->resolveTenantIdStrict($request);

        if ((int) $proposalTemplate->tenant_id !== $tenantId) {
            abort(404, 'Proposal template not found.');
        }

        $proposalTemplateId = (int) $proposalTemplate->id;
        $proposalTemplate->delete();

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => $tenantId,
            'actor_id' => $request->user()?->id,
            'type' => 'proposal.template.deleted',
            'subject_type' => ProposalTemplate::class,
            'subject_id' => $proposalTemplateId,
            'description' => 'Proposal template deleted from admin module.',
        ]);

        $eventService->emit(
            eventName: 'proposal.template.deleted',
            tenantId: $tenantId,
            subjectType: ProposalTemplate::class,
            subjectId: $proposalTemplateId,
            payload: [],
        );

        return response()->json([
            'message' => 'Proposal template deleted successfully.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(
        Request $request,
        int $tenantId,
        bool $isUpdate = false,
        ?ProposalTemplate $proposalTemplate = null,
    ): array {
        $slugRule = Rule::unique('proposal_templates', 'slug')
            ->where(fn ($builder) => $builder->where('tenant_id', $tenantId));

        if ($proposalTemplate !== null) {
            $slugRule->ignore((int) $proposalTemplate->id);
        }

        $rules = [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', $slugRule],
            'service' => ['sometimes', 'nullable', 'string', 'max:120'],
            'currency' => ['sometimes', 'nullable', 'string', 'max:8'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
            'body_html' => ['sometimes', 'string'],
            'body_text' => ['sometimes', 'nullable', 'string'],
            'settings' => ['sometimes', 'nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            'brand_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:brands,id'],
        ];

        if (! $isUpdate) {
            $rules['name'][] = 'required';
            $rules['body_html'][] = 'required';
        }

        $payload = $request->validate($rules);

        if (array_key_exists('brand_id', $payload) && is_numeric($payload['brand_id'])) {
            $brandExists = Brand::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->whereKey((int) $payload['brand_id'])
                ->exists();

            if (! $brandExists) {
                abort(422, 'Provided brand_id does not belong to active tenant.');
            }
        }

        return $payload;
    }

    private function resolveTenantIdStrict(Request $request): int
    {
        $tenantId = $this->resolveTenantIdForPermission($request, $request->user());

        if ($tenantId !== null && Tenant::query()->whereKey($tenantId)->exists()) {
            return $tenantId;
        }

        abort(422, 'Tenant context is required.');
    }
}
