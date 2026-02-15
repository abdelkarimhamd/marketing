<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomField;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CustomFieldController extends Controller
{
    /**
     * List custom fields for entity.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'leads.view');
        $payload = $request->validate([
            'entity' => ['nullable', Rule::in(['lead', 'deal'])],
        ]);

        $rows = CustomField::query()
            ->when(
                isset($payload['entity']),
                fn ($query) => $query->where('entity', $payload['entity'])
            )
            ->orderBy('entity')
            ->orderBy('name')
            ->get();

        return response()->json([
            'custom_fields' => $rows,
        ]);
    }

    /**
     * Create custom field.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'leads.update');
        $tenantId = $this->tenantId($request);
        $payload = $this->validatePayload($request, $tenantId);

        $field = CustomField::query()->withoutTenancy()->create([
            'tenant_id' => $tenantId,
            'entity' => $payload['entity'],
            'name' => $payload['name'],
            'slug' => $payload['slug'] ?? Str::slug($payload['name']),
            'field_type' => $payload['field_type'],
            'is_required' => $payload['is_required'] ?? false,
            'is_active' => $payload['is_active'] ?? true,
            'options' => $payload['options'] ?? [],
            'validation_rules' => $payload['validation_rules'] ?? [],
            'permissions' => $payload['permissions'] ?? [],
        ]);

        return response()->json([
            'message' => 'Custom field created.',
            'custom_field' => $field,
        ], 201);
    }

    /**
     * Update custom field.
     */
    public function update(Request $request, CustomField $customField): JsonResponse
    {
        $this->authorizePermission($request, 'leads.update');
        $tenantId = $this->tenantId($request);

        if ((int) $customField->tenant_id !== $tenantId) {
            abort(404, 'Custom field not found in tenant scope.');
        }

        $payload = $this->validatePayload($request, $tenantId, true, $customField->id);
        $customField->fill($payload)->save();

        return response()->json([
            'message' => 'Custom field updated.',
            'custom_field' => $customField,
        ]);
    }

    /**
     * Delete custom field.
     */
    public function destroy(Request $request, CustomField $customField): JsonResponse
    {
        $this->authorizePermission($request, 'leads.update');
        $tenantId = $this->tenantId($request);

        if ((int) $customField->tenant_id !== $tenantId) {
            abort(404, 'Custom field not found in tenant scope.');
        }

        $customField->delete();

        return response()->json([
            'message' => 'Custom field deleted.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, int $tenantId, bool $isUpdate = false, ?int $ignoreId = null): array
    {
        $slugRule = Rule::unique('custom_fields', 'slug')
            ->where(fn ($query) => $query
                ->where('tenant_id', $tenantId)
                ->where('entity', $request->input('entity', 'lead')));

        if ($ignoreId !== null) {
            $slugRule->ignore($ignoreId);
        }

        $rules = [
            'entity' => [$isUpdate ? 'sometimes' : 'required', Rule::in(['lead', 'deal'])],
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:120'],
            'slug' => ['sometimes', 'string', 'max:120', $slugRule],
            'field_type' => [$isUpdate ? 'sometimes' : 'required', Rule::in(['text', 'number', 'select', 'date', 'boolean'])],
            'is_required' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'options' => ['sometimes', 'array'],
            'validation_rules' => ['sometimes', 'array'],
            'permissions' => ['sometimes', 'array'],
        ];

        return $request->validate($rules);
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

