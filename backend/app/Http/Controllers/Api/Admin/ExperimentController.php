<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Experiment;
use App\Models\ExperimentVariant;
use App\Services\ExperimentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ExperimentController extends Controller
{
    /**
     * List experiments.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'experiments.view');
        $tenantId = $this->tenantId($request);

        $payload = $request->validate([
            'scope' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', 'string', 'max:40'],
        ]);

        $query = Experiment::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->with('variants');

        if (is_string($payload['scope'] ?? null) && trim((string) $payload['scope']) !== '') {
            $query->where('scope', trim((string) $payload['scope']));
        }

        if (is_string($payload['status'] ?? null) && trim((string) $payload['status']) !== '') {
            $query->where('status', trim((string) $payload['status']));
        }

        return response()->json([
            'data' => $query->orderByDesc('id')->get()->map(fn (Experiment $experiment): array => $this->map($experiment))->values(),
        ]);
    }

    /**
     * Create one experiment.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'experiments.create');
        $tenantId = $this->tenantId($request);
        $payload = $this->validatePayload($request, false);

        $experiment = DB::transaction(function () use ($tenantId, $payload): Experiment {
            $experiment = Experiment::query()
                ->withoutTenancy()
                ->create([
                    'tenant_id' => $tenantId,
                    'name' => trim((string) $payload['name']),
                    'scope' => (string) ($payload['scope'] ?? 'landing'),
                    'status' => (string) ($payload['status'] ?? 'draft'),
                    'holdout_pct' => (float) ($payload['holdout_pct'] ?? 0),
                    'start_at' => $payload['start_at'] ?? null,
                    'end_at' => $payload['end_at'] ?? null,
                    'config_json' => is_array($payload['config_json'] ?? null) ? $payload['config_json'] : [],
                ]);

            $this->syncVariants($experiment, $tenantId, is_array($payload['variants'] ?? null) ? $payload['variants'] : []);

            return $experiment->refresh()->load('variants');
        });

        return response()->json([
            'message' => 'Experiment created successfully.',
            'experiment' => $this->map($experiment),
        ], 201);
    }

    /**
     * Show one experiment.
     */
    public function show(Request $request, Experiment $experiment): JsonResponse
    {
        $this->authorizePermission($request, 'experiments.view');
        $tenantId = $this->tenantId($request);
        $this->ensureTenantExperiment($experiment, $tenantId);

        return response()->json([
            'experiment' => $this->map($experiment->load('variants')),
        ]);
    }

    /**
     * Update one experiment.
     */
    public function update(Request $request, Experiment $experiment): JsonResponse
    {
        $this->authorizePermission($request, 'experiments.update');
        $tenantId = $this->tenantId($request);
        $this->ensureTenantExperiment($experiment, $tenantId);
        $payload = $this->validatePayload($request, true);

        $updated = DB::transaction(function () use ($experiment, $tenantId, $payload): Experiment {
            $experiment->forceFill([
                'name' => array_key_exists('name', $payload) ? trim((string) $payload['name']) : $experiment->name,
                'scope' => array_key_exists('scope', $payload) ? (string) $payload['scope'] : $experiment->scope,
                'status' => array_key_exists('status', $payload) ? (string) $payload['status'] : $experiment->status,
                'holdout_pct' => array_key_exists('holdout_pct', $payload) ? (float) $payload['holdout_pct'] : $experiment->holdout_pct,
                'start_at' => array_key_exists('start_at', $payload) ? $payload['start_at'] : $experiment->start_at,
                'end_at' => array_key_exists('end_at', $payload) ? $payload['end_at'] : $experiment->end_at,
                'config_json' => array_key_exists('config_json', $payload)
                    ? (is_array($payload['config_json']) ? $payload['config_json'] : [])
                    : $experiment->config_json,
            ])->save();

            if (array_key_exists('variants', $payload)) {
                $this->syncVariants($experiment, $tenantId, is_array($payload['variants']) ? $payload['variants'] : []);
            }

            return $experiment->refresh()->load('variants');
        });

        return response()->json([
            'message' => 'Experiment updated successfully.',
            'experiment' => $this->map($updated),
        ]);
    }

    /**
     * Delete one experiment.
     */
    public function destroy(Request $request, Experiment $experiment): JsonResponse
    {
        $this->authorizePermission($request, 'experiments.delete');
        $tenantId = $this->tenantId($request);
        $this->ensureTenantExperiment($experiment, $tenantId);
        $experiment->delete();

        return response()->json([
            'message' => 'Experiment deleted successfully.',
        ]);
    }

    /**
     * Results dashboard payload.
     */
    public function results(Request $request, Experiment $experiment, ExperimentService $service): JsonResponse
    {
        $this->authorizePermission($request, 'experiments.view');
        $tenantId = $this->tenantId($request);
        $this->ensureTenantExperiment($experiment, $tenantId);

        return response()->json($service->results($experiment));
    }

    /**
     * @param list<array<string, mixed>> $variants
     */
    private function syncVariants(Experiment $experiment, int $tenantId, array $variants): void
    {
        ExperimentVariant::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('experiment_id', (int) $experiment->id)
            ->delete();

        if ($variants === []) {
            ExperimentVariant::query()
                ->withoutTenancy()
                ->create([
                    'tenant_id' => $tenantId,
                    'experiment_id' => (int) $experiment->id,
                    'key' => 'control',
                    'weight' => 100,
                    'is_control' => true,
                    'config_json' => [],
                ]);

            return;
        }

        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            $key = trim((string) ($variant['key'] ?? ''));

            if ($key === '') {
                continue;
            }

            ExperimentVariant::query()
                ->withoutTenancy()
                ->create([
                    'tenant_id' => $tenantId,
                    'experiment_id' => (int) $experiment->id,
                    'key' => $key,
                    'weight' => max(1, (int) ($variant['weight'] ?? 100)),
                    'is_control' => (bool) ($variant['is_control'] ?? false),
                    'config_json' => is_array($variant['config_json'] ?? null) ? $variant['config_json'] : [],
                ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function map(Experiment $experiment): array
    {
        return [
            'id' => (int) $experiment->id,
            'tenant_id' => (int) $experiment->tenant_id,
            'name' => $experiment->name,
            'scope' => $experiment->scope,
            'status' => $experiment->status,
            'holdout_pct' => (float) $experiment->holdout_pct,
            'start_at' => optional($experiment->start_at)->toIso8601String(),
            'end_at' => optional($experiment->end_at)->toIso8601String(),
            'config_json' => is_array($experiment->config_json) ? $experiment->config_json : [],
            'variants' => $experiment->relationLoaded('variants')
                ? $experiment->variants->map(static fn (ExperimentVariant $variant): array => [
                    'id' => (int) $variant->id,
                    'key' => $variant->key,
                    'weight' => (int) $variant->weight,
                    'is_control' => (bool) $variant->is_control,
                    'config_json' => is_array($variant->config_json) ? $variant->config_json : [],
                ])->values()->all()
                : [],
            'created_at' => optional($experiment->created_at)->toIso8601String(),
            'updated_at' => optional($experiment->updated_at)->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, bool $isUpdate): array
    {
        $nameRule = $isUpdate ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$nameRule, 'string', 'max:255'],
            'scope' => ['sometimes', Rule::in(['landing', 'campaign', 'journey'])],
            'status' => ['sometimes', Rule::in(['draft', 'running', 'paused', 'completed'])],
            'holdout_pct' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'start_at' => ['sometimes', 'nullable', 'date'],
            'end_at' => ['sometimes', 'nullable', 'date'],
            'config_json' => ['sometimes', 'array'],
            'variants' => ['sometimes', 'array', 'max:20'],
            'variants.*.key' => ['required_with:variants', 'string', 'max:80'],
            'variants.*.weight' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'variants.*.is_control' => ['nullable', 'boolean'],
            'variants.*.config_json' => ['nullable', 'array'],
        ]);
    }

    private function ensureTenantExperiment(Experiment $experiment, int $tenantId): void
    {
        if ((int) $experiment->tenant_id !== $tenantId) {
            abort(404, 'Experiment not found in tenant scope.');
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
