<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PersonalizationRule;
use App\Models\PersonalizationVariant;
use App\Services\PersonalizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PersonalizationController extends Controller
{
    /**
     * List personalization rules.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'personalization.view');
        $tenantId = $this->tenantId($request);

        $rules = PersonalizationRule::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->with('variants')
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $rules->map(fn (PersonalizationRule $rule): array => $this->mapRule($rule))->values(),
        ]);
    }

    /**
     * Create one personalization rule.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'personalization.create');
        $tenantId = $this->tenantId($request);
        $payload = $this->validatePayload($request, false);

        $rule = DB::transaction(function () use ($request, $tenantId, $payload): PersonalizationRule {
            $rule = PersonalizationRule::query()
                ->withoutTenancy()
                ->create([
                    'tenant_id' => $tenantId,
                    'name' => trim((string) $payload['name']),
                    'priority' => (int) ($payload['priority'] ?? 100),
                    'enabled' => (bool) ($payload['enabled'] ?? true),
                    'match_rules_json' => is_array($payload['match_rules_json'] ?? null) ? $payload['match_rules_json'] : [],
                    'settings' => is_array($payload['settings'] ?? null) ? $payload['settings'] : [],
                    'created_by' => $request->user()?->id,
                    'updated_by' => $request->user()?->id,
                ]);

            $this->syncVariants($rule, $tenantId, is_array($payload['variants'] ?? null) ? $payload['variants'] : []);

            return $rule->refresh()->load('variants');
        });

        return response()->json([
            'message' => 'Personalization rule created successfully.',
            'rule' => $this->mapRule($rule),
        ], 201);
    }

    /**
     * Show one personalization rule.
     */
    public function show(Request $request, PersonalizationRule $personalizationRule): JsonResponse
    {
        $this->authorizePermission($request, 'personalization.view');
        $tenantId = $this->tenantId($request);
        $this->ensureTenantRule($personalizationRule, $tenantId);

        return response()->json([
            'rule' => $this->mapRule($personalizationRule->load('variants')),
        ]);
    }

    /**
     * Update one personalization rule.
     */
    public function update(Request $request, PersonalizationRule $personalizationRule): JsonResponse
    {
        $this->authorizePermission($request, 'personalization.update');
        $tenantId = $this->tenantId($request);
        $this->ensureTenantRule($personalizationRule, $tenantId);
        $payload = $this->validatePayload($request, true);

        $rule = DB::transaction(function () use ($request, $tenantId, $payload, $personalizationRule): PersonalizationRule {
            $personalizationRule->forceFill([
                'name' => array_key_exists('name', $payload) ? trim((string) $payload['name']) : $personalizationRule->name,
                'priority' => array_key_exists('priority', $payload) ? (int) $payload['priority'] : $personalizationRule->priority,
                'enabled' => array_key_exists('enabled', $payload) ? (bool) $payload['enabled'] : $personalizationRule->enabled,
                'match_rules_json' => array_key_exists('match_rules_json', $payload)
                    ? (is_array($payload['match_rules_json']) ? $payload['match_rules_json'] : [])
                    : $personalizationRule->match_rules_json,
                'settings' => array_key_exists('settings', $payload)
                    ? (is_array($payload['settings']) ? $payload['settings'] : [])
                    : $personalizationRule->settings,
                'updated_by' => $request->user()?->id,
            ])->save();

            if (array_key_exists('variants', $payload)) {
                $this->syncVariants(
                    $personalizationRule,
                    $tenantId,
                    is_array($payload['variants']) ? $payload['variants'] : []
                );
            }

            return $personalizationRule->refresh()->load('variants');
        });

        return response()->json([
            'message' => 'Personalization rule updated successfully.',
            'rule' => $this->mapRule($rule),
        ]);
    }

    /**
     * Delete one personalization rule.
     */
    public function destroy(Request $request, PersonalizationRule $personalizationRule): JsonResponse
    {
        $this->authorizePermission($request, 'personalization.delete');
        $tenantId = $this->tenantId($request);
        $this->ensureTenantRule($personalizationRule, $tenantId);
        $personalizationRule->delete();

        return response()->json([
            'message' => 'Personalization rule deleted successfully.',
        ]);
    }

    /**
     * Simulate rule evaluation for preview.
     */
    public function preview(
        Request $request,
        PersonalizationRule $personalizationRule,
        PersonalizationService $service
    ): JsonResponse {
        $this->authorizePermission($request, 'personalization.view');
        $tenantId = $this->tenantId($request);
        $this->ensureTenantRule($personalizationRule, $tenantId);

        $payload = $request->validate([
            'path' => ['nullable', 'string', 'max:1000'],
            'visitor_id' => ['nullable', 'string', 'max:64'],
            'source' => ['nullable', 'string', 'max:120'],
            'device' => ['nullable', 'string', 'max:32'],
            'utm' => ['nullable', 'array'],
        ]);

        return response()->json([
            'preview' => $service->simulateForRule($personalizationRule->load('variants'), [
                'path' => $payload['path'] ?? '/',
                'visitor_id' => $payload['visitor_id'] ?? '',
                'source' => $payload['source'] ?? '',
                'device' => $payload['device'] ?? '',
                'utm' => is_array($payload['utm'] ?? null) ? $payload['utm'] : [],
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRule(PersonalizationRule $rule): array
    {
        return [
            'id' => (int) $rule->id,
            'tenant_id' => (int) $rule->tenant_id,
            'name' => $rule->name,
            'priority' => (int) $rule->priority,
            'enabled' => (bool) $rule->enabled,
            'match_rules_json' => is_array($rule->match_rules_json) ? $rule->match_rules_json : [],
            'settings' => is_array($rule->settings) ? $rule->settings : [],
            'variants' => $rule->relationLoaded('variants')
                ? $rule->variants->map(static fn (PersonalizationVariant $variant): array => [
                    'id' => (int) $variant->id,
                    'variant_key' => $variant->variant_key,
                    'weight' => (int) $variant->weight,
                    'is_control' => (bool) $variant->is_control,
                    'changes_json' => is_array($variant->changes_json) ? $variant->changes_json : [],
                ])->values()->all()
                : [],
            'created_at' => optional($rule->created_at)->toIso8601String(),
            'updated_at' => optional($rule->updated_at)->toIso8601String(),
        ];
    }

    /**
     * @param list<array<string, mixed>> $variants
     */
    private function syncVariants(PersonalizationRule $rule, int $tenantId, array $variants): void
    {
        PersonalizationVariant::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('personalization_rule_id', (int) $rule->id)
            ->delete();

        if ($variants === []) {
            PersonalizationVariant::query()
                ->withoutTenancy()
                ->create([
                    'tenant_id' => $tenantId,
                    'personalization_rule_id' => (int) $rule->id,
                    'variant_key' => 'default',
                    'weight' => 100,
                    'is_control' => false,
                    'changes_json' => [],
                ]);

            return;
        }

        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            $key = trim((string) ($variant['variant_key'] ?? ''));

            if ($key === '') {
                continue;
            }

            PersonalizationVariant::query()
                ->withoutTenancy()
                ->create([
                    'tenant_id' => $tenantId,
                    'personalization_rule_id' => (int) $rule->id,
                    'variant_key' => $key,
                    'weight' => max(1, (int) ($variant['weight'] ?? 100)),
                    'is_control' => (bool) ($variant['is_control'] ?? false),
                    'changes_json' => is_array($variant['changes_json'] ?? null) ? $variant['changes_json'] : [],
                ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, bool $isUpdate): array
    {
        $nameRule = $isUpdate ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$nameRule, 'string', 'max:255'],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'enabled' => ['sometimes', 'boolean'],
            'match_rules_json' => ['sometimes', 'array'],
            'settings' => ['sometimes', 'array'],
            'variants' => ['sometimes', 'array', 'max:20'],
            'variants.*.variant_key' => ['required_with:variants', 'string', 'max:80'],
            'variants.*.weight' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'variants.*.is_control' => ['nullable', 'boolean'],
            'variants.*.changes_json' => ['nullable', 'array'],
        ]);
    }

    private function ensureTenantRule(PersonalizationRule $rule, int $tenantId): void
    {
        if ((int) $rule->tenant_id !== $tenantId) {
            abort(404, 'Personalization rule not found in tenant scope.');
        }
    }

    private function tenantId(Request $request): int
    {
        $tenantId = $this->resolveTenantIdForPermission($request, $request->user());

        if ($tenantId === null || $tenantId <= 0) {
            abort(422, 'Tenant context is required.');
        }

        return $tenantId;
    }
}
