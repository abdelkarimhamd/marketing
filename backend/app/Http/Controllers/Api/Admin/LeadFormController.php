<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomField;
use App\Models\LeadForm;
use App\Models\LeadFormField;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LeadFormController extends Controller
{
    /**
     * List lead forms with mapped fields.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'leads.view');

        $rows = LeadForm::query()
            ->with('fields.customField')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'forms' => $rows,
        ]);
    }

    /**
     * Create one lead form with mapping rules.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'leads.update');
        $tenantId = $this->tenantId($request);
        $payload = $this->validatePayload($request, $tenantId);

        $form = DB::transaction(function () use ($payload, $tenantId): LeadForm {
            $form = LeadForm::query()->withoutTenancy()->create([
                'tenant_id' => $tenantId,
                'name' => $payload['name'],
                'slug' => $payload['slug'] ?? Str::slug($payload['name']),
                'is_active' => $payload['is_active'] ?? true,
                'settings' => $payload['settings'] ?? [],
            ]);

            $this->syncFields($form, $payload['fields'] ?? []);

            return $form;
        });

        return response()->json([
            'message' => 'Lead form created.',
            'form' => $form->load('fields.customField'),
        ], 201);
    }

    /**
     * Update lead form and mappings.
     */
    public function update(Request $request, LeadForm $leadForm): JsonResponse
    {
        $this->authorizePermission($request, 'leads.update');
        $tenantId = $this->tenantId($request);

        if ((int) $leadForm->tenant_id !== $tenantId) {
            abort(404, 'Lead form not found in tenant scope.');
        }

        $payload = $this->validatePayload($request, $tenantId, true, $leadForm->id);

        DB::transaction(function () use ($leadForm, $payload): void {
            $leadForm->fill([
                'name' => $payload['name'] ?? $leadForm->name,
                'slug' => $payload['slug'] ?? $leadForm->slug,
                'is_active' => $payload['is_active'] ?? $leadForm->is_active,
                'settings' => $payload['settings'] ?? $leadForm->settings,
            ])->save();

            if (array_key_exists('fields', $payload)) {
                $this->syncFields($leadForm, $payload['fields'] ?? []);
            }
        });

        return response()->json([
            'message' => 'Lead form updated.',
            'form' => $leadForm->refresh()->load('fields.customField'),
        ]);
    }

    /**
     * Delete lead form.
     */
    public function destroy(Request $request, LeadForm $leadForm): JsonResponse
    {
        $this->authorizePermission($request, 'leads.update');
        $tenantId = $this->tenantId($request);

        if ((int) $leadForm->tenant_id !== $tenantId) {
            abort(404, 'Lead form not found in tenant scope.');
        }

        $leadForm->delete();

        return response()->json([
            'message' => 'Lead form deleted.',
        ]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function syncFields(LeadForm $form, array $rows): void
    {
        LeadFormField::query()
            ->withoutTenancy()
            ->where('tenant_id', $form->tenant_id)
            ->where('lead_form_id', $form->id)
            ->delete();

        foreach ($rows as $index => $row) {
            $customFieldId = isset($row['custom_field_id']) ? (int) $row['custom_field_id'] : null;

            if ($customFieldId !== null && $customFieldId > 0) {
                $exists = CustomField::query()
                    ->withoutTenancy()
                    ->where('tenant_id', $form->tenant_id)
                    ->whereKey($customFieldId)
                    ->exists();

                if (! $exists) {
                    abort(422, 'One or more custom_field_id values do not belong to tenant.');
                }
            }

            LeadFormField::query()->withoutTenancy()->create([
                'tenant_id' => $form->tenant_id,
                'lead_form_id' => $form->id,
                'custom_field_id' => $customFieldId,
                'label' => (string) ($row['label'] ?? 'Field '.($index + 1)),
                'source_key' => (string) ($row['source_key'] ?? ''),
                'map_to' => (string) ($row['map_to'] ?? 'meta'),
                'sort_order' => (int) ($row['sort_order'] ?? ($index + 1)),
                'is_required' => (bool) ($row['is_required'] ?? false),
                'validation_rules' => is_array($row['validation_rules'] ?? null) ? $row['validation_rules'] : [],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, int $tenantId, bool $isUpdate = false, ?int $ignoreId = null): array
    {
        $slugRule = Rule::unique('lead_forms', 'slug')
            ->where(fn ($query) => $query->where('tenant_id', $tenantId));

        if ($ignoreId !== null) {
            $slugRule->ignore($ignoreId);
        }

        return $request->validate([
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:150'],
            'slug' => ['sometimes', 'string', 'max:120', $slugRule],
            'is_active' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'array'],
            'fields' => ['sometimes', 'array', 'max:200'],
            'fields.*.custom_field_id' => ['nullable', 'integer', 'exists:custom_fields,id'],
            'fields.*.label' => ['required_with:fields', 'string', 'max:150'],
            'fields.*.source_key' => ['required_with:fields', 'string', 'max:120'],
            'fields.*.map_to' => ['nullable', Rule::in([
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
                'meta',
                'custom',
            ])],
            'fields.*.sort_order' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'fields.*.is_required' => ['nullable', 'boolean'],
            'fields.*.validation_rules' => ['nullable', 'array'],
        ]);
    }

    private function tenantId(Request $request): int
    {
        $tenantId = $request->attributes->get('tenant_id');

        if (is_int($tenantId) && $tenantId > 0) {
            return $tenantId;
        }

        $requested = $request->query('tenant_id', $request->input('tenant_id'));

        if (is_numeric($requested) && (int) $requested > 0) {
            return (int) $requested;
        }

        abort(422, 'Tenant context is required.');
    }
}

