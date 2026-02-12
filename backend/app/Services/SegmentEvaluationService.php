<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Segment;
use Illuminate\Database\Eloquent\Builder;

class SegmentEvaluationService
{
    /**
     * Allowed lead fields in segment rules.
     *
     * @var array<string, string>
     */
    private const FIELD_MAP = [
        'id' => 'id',
        'first_name' => 'first_name',
        'last_name' => 'last_name',
        'email' => 'email',
        'phone' => 'phone',
        'company' => 'company',
        'city' => 'city',
        'interest' => 'interest',
        'service' => 'service',
        'status' => 'status',
        'source' => 'source',
        'score' => 'score',
        'email_consent' => 'email_consent',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
    ];

    /**
     * Query leads for a persisted segment.
     */
    public function queryForSegment(Segment $segment): Builder
    {
        $rules = $segment->rules_json ?? $segment->filters ?? null;

        return $this->queryForRules((int) $segment->tenant_id, is_array($rules) ? $rules : null);
    }

    /**
     * Query leads for tenant + rules payload.
     *
     * @param array<string, mixed>|null $rules
     */
    public function queryForRules(int $tenantId, ?array $rules): Builder
    {
        $query = Lead::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId);

        if ($rules === null || $rules === []) {
            return $query;
        }

        $this->applyGroup($query, $rules, 'and');

        return $query;
    }

    /**
     * Validate builder rules payload shape.
     *
     * @param array<string, mixed>|null $rules
     */
    public function validateRules(?array $rules): void
    {
        if ($rules === null || $rules === []) {
            return;
        }

        if (! isset($rules['operator']) || ! in_array(strtoupper((string) $rules['operator']), ['AND', 'OR'], true)) {
            abort(422, "rules_json.operator must be 'AND' or 'OR'.");
        }

        if (! isset($rules['rules']) || ! is_array($rules['rules'])) {
            abort(422, 'rules_json.rules must be an array.');
        }

        foreach ($rules['rules'] as $rule) {
            if (! is_array($rule)) {
                abort(422, 'Each segment rule must be an object.');
            }

            if (array_key_exists('rules', $rule)) {
                $this->validateRules($rule);

                continue;
            }

            $field = (string) ($rule['field'] ?? '');
            $operator = (string) ($rule['operator'] ?? '');

            if (! array_key_exists($field, self::FIELD_MAP)) {
                abort(422, "Unsupported segment field '{$field}'.");
            }

            if (! in_array($operator, $this->allowedOperators(), true)) {
                abort(422, "Unsupported segment operator '{$operator}'.");
            }
        }
    }

    /**
     * Return allowed segment fields.
     *
     * @return list<string>
     */
    public function allowedFields(): array
    {
        return array_keys(self::FIELD_MAP);
    }

    /**
     * Return allowed condition operators.
     *
     * @return list<string>
     */
    public function allowedOperators(): array
    {
        return [
            'equals',
            'not_equals',
            'contains',
            'starts_with',
            'ends_with',
            'in',
            'not_in',
            'gt',
            'gte',
            'lt',
            'lte',
            'between',
            'is_null',
            'is_not_null',
        ];
    }

    /**
     * Apply a rule group (AND/OR) recursively.
     *
     * @param array<string, mixed> $group
     */
    private function applyGroup(Builder $query, array $group, string $boolean): void
    {
        $method = $boolean === 'or' ? 'orWhere' : 'where';
        $operator = strtoupper((string) ($group['operator'] ?? 'AND'));
        $children = is_array($group['rules'] ?? null) ? $group['rules'] : [];

        $query->{$method}(function (Builder $nested) use ($children, $operator): void {
            foreach ($children as $index => $rule) {
                if (! is_array($rule)) {
                    continue;
                }

                $childBoolean = $index === 0 ? 'and' : ($operator === 'OR' ? 'or' : 'and');

                if (array_key_exists('rules', $rule)) {
                    $this->applyGroup($nested, $rule, $childBoolean);

                    continue;
                }

                $this->applyCondition($nested, $rule, $childBoolean);
            }
        });
    }

    /**
     * Apply one leaf rule.
     *
     * @param array<string, mixed> $rule
     */
    private function applyCondition(Builder $query, array $rule, string $boolean): void
    {
        $field = (string) ($rule['field'] ?? '');
        $operator = (string) ($rule['operator'] ?? 'equals');
        $value = $rule['value'] ?? null;

        if (! array_key_exists($field, self::FIELD_MAP)) {
            return;
        }

        $column = self::FIELD_MAP[$field];
        $whereMethod = $boolean === 'or' ? 'orWhere' : 'where';

        switch ($operator) {
            case 'equals':
                $query->{$whereMethod}($column, '=', $value);
                break;
            case 'not_equals':
                $query->{$whereMethod}($column, '!=', $value);
                break;
            case 'contains':
                $query->{$whereMethod}($column, 'like', '%'.$this->escapeLike((string) $value).'%');
                break;
            case 'starts_with':
                $query->{$whereMethod}($column, 'like', $this->escapeLike((string) $value).'%');
                break;
            case 'ends_with':
                $query->{$whereMethod}($column, 'like', '%'.$this->escapeLike((string) $value));
                break;
            case 'in':
                $this->applyIn($query, $column, $value, $boolean, negate: false);
                break;
            case 'not_in':
                $this->applyIn($query, $column, $value, $boolean, negate: true);
                break;
            case 'gt':
                $query->{$whereMethod}($column, '>', $value);
                break;
            case 'gte':
                $query->{$whereMethod}($column, '>=', $value);
                break;
            case 'lt':
                $query->{$whereMethod}($column, '<', $value);
                break;
            case 'lte':
                $query->{$whereMethod}($column, '<=', $value);
                break;
            case 'between':
                $this->applyBetween($query, $column, $value, $boolean);
                break;
            case 'is_null':
                $this->applyNull($query, $column, $boolean, negate: false);
                break;
            case 'is_not_null':
                $this->applyNull($query, $column, $boolean, negate: true);
                break;
        }
    }

    /**
     * Apply IN or NOT IN expression.
     */
    private function applyIn(Builder $query, string $column, mixed $value, string $boolean, bool $negate): void
    {
        $values = collect(is_array($value) ? $value : [$value])
            ->filter(static fn (mixed $item): bool => $item !== null && $item !== '')
            ->values()
            ->all();

        if ($values === []) {
            return;
        }

        if ($boolean === 'or') {
            if ($negate) {
                $query->orWhereNotIn($column, $values);
            } else {
                $query->orWhereIn($column, $values);
            }

            return;
        }

        if ($negate) {
            $query->whereNotIn($column, $values);
        } else {
            $query->whereIn($column, $values);
        }
    }

    /**
     * Apply BETWEEN expression.
     */
    private function applyBetween(Builder $query, string $column, mixed $value, string $boolean): void
    {
        if (! is_array($value) || count($value) < 2) {
            return;
        }

        $range = [array_values($value)[0], array_values($value)[1]];

        if ($boolean === 'or') {
            $query->orWhereBetween($column, $range);

            return;
        }

        $query->whereBetween($column, $range);
    }

    /**
     * Apply NULL / NOT NULL expression.
     */
    private function applyNull(Builder $query, string $column, string $boolean, bool $negate): void
    {
        if ($boolean === 'or') {
            if ($negate) {
                $query->orWhereNotNull($column);
            } else {
                $query->orWhereNull($column);
            }

            return;
        }

        if ($negate) {
            $query->whereNotNull($column);
        } else {
            $query->whereNull($column);
        }
    }

    /**
     * Escape wildcard characters for LIKE search.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
