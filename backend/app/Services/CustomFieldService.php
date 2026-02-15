<?php

namespace App\Services;

use App\Models\CustomField;
use App\Models\Lead;
use App\Models\LeadCustomFieldValue;
use App\Models\LeadForm;
use App\Models\LeadFormField;

class CustomFieldService
{
    /**
     * Map public form payload into lead attributes and custom field values.
     *
     * @param array<string, mixed> $payload
     * @return array{lead: array<string, mixed>, custom_values: array<int, mixed>}
     */
    public function mapFormPayload(LeadForm $form, array $payload): array
    {
        $lead = [];
        $customValues = [];

        $fields = LeadFormField::query()
            ->withoutTenancy()
            ->where('tenant_id', $form->tenant_id)
            ->where('lead_form_id', $form->id)
            ->orderBy('sort_order')
            ->get();

        foreach ($fields as $field) {
            $sourceKey = $field->source_key;
            $value = $payload[$sourceKey] ?? null;

            if ($field->is_required && ($value === null || $value === '')) {
                abort(422, "Field '{$field->label}' is required.");
            }

            if ($field->map_to === 'meta') {
                $lead['meta'] = array_merge(
                    is_array($lead['meta'] ?? null) ? $lead['meta'] : [],
                    [$sourceKey => $value]
                );

                continue;
            }

            if ($field->map_to === 'custom' && $field->custom_field_id !== null) {
                $customValues[(int) $field->custom_field_id] = $value;
                continue;
            }

            $lead[$field->map_to] = $value;
        }

        return [
            'lead' => $lead,
            'custom_values' => $customValues,
        ];
    }

    /**
     * Upsert custom field values for one lead.
     *
     * @param array<int, mixed> $valuesByFieldId
     */
    public function upsertLeadValues(Lead $lead, array $valuesByFieldId): void
    {
        foreach ($valuesByFieldId as $fieldId => $value) {
            $field = CustomField::query()
                ->withoutTenancy()
                ->where('tenant_id', $lead->tenant_id)
                ->whereKey((int) $fieldId)
                ->first();

            if ($field === null || ! $field->is_active) {
                continue;
            }

            $this->assertValueType($field, $value);

            LeadCustomFieldValue::query()
                ->withoutTenancy()
                ->updateOrCreate(
                    [
                        'tenant_id' => $lead->tenant_id,
                        'lead_id' => $lead->id,
                        'custom_field_id' => $field->id,
                    ],
                    [
                        'value' => ['value' => $value],
                    ]
                );
        }
    }

    /**
     * Validate field value type by custom field type.
     */
    private function assertValueType(CustomField $field, mixed $value): void
    {
        if ($value === null || $value === '') {
            if ($field->is_required) {
                abort(422, "Custom field '{$field->name}' is required.");
            }

            return;
        }

        $type = mb_strtolower($field->field_type);

        if ($type === 'number' && ! is_numeric($value)) {
            abort(422, "Custom field '{$field->name}' requires a numeric value.");
        }

        if ($type === 'boolean' && ! is_bool($value)) {
            abort(422, "Custom field '{$field->name}' requires true/false.");
        }

        if ($type === 'date' && strtotime((string) $value) === false) {
            abort(422, "Custom field '{$field->name}' requires a valid date.");
        }

        if ($type === 'select') {
            $options = is_array($field->options) ? ($field->options['choices'] ?? $field->options) : [];
            $allowed = array_map(static fn (mixed $row): string => (string) $row, is_array($options) ? $options : []);

            if ($allowed !== [] && ! in_array((string) $value, $allowed, true)) {
                abort(422, "Custom field '{$field->name}' has invalid selection.");
            }
        }
    }
}

