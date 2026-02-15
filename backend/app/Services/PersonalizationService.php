<?php

namespace App\Services;

use App\Models\Experiment;
use App\Models\PersonalizationRule;
use App\Models\PersonalizationVariant;

class PersonalizationService
{
    /**
     * Select one active rule/variant and return safe patch response.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    public function resolveForTenant(int $tenantId, array $context): ?array
    {
        if (! (bool) config('features.personalization.enabled', true)) {
            return null;
        }

        $path = $this->normalizePath($context['path'] ?? null);
        $utm = is_array($context['utm'] ?? null) ? $context['utm'] : [];
        $source = trim((string) ($context['source'] ?? ''));
        $device = trim((string) ($context['device'] ?? ''));
        $visitorId = trim((string) ($context['visitor_id'] ?? ''));

        $rules = PersonalizationRule::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->with('variants')
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            $matchRules = is_array($rule->match_rules_json) ? $rule->match_rules_json : [];

            if (! $this->matches($matchRules, $path, $source, $device, $utm)) {
                continue;
            }

            $variant = $this->resolveVariantWithExperiment($rule, $visitorId, $path, $source);

            if (! $variant instanceof PersonalizationVariant) {
                continue;
            }

            $patch = $this->sanitizePatch($variant->changes_json);

            return [
                'rule_id' => (int) $rule->id,
                'rule_name' => (string) $rule->name,
                'variant_id' => (int) $variant->id,
                'variant_key' => (string) $variant->variant_key,
                'patch' => $patch,
            ];
        }

        return null;
    }

    private function resolveVariantWithExperiment(
        PersonalizationRule $rule,
        string $visitorId,
        string $path,
        string $source
    ): ?PersonalizationVariant {
        $settings = is_array($rule->settings) ? $rule->settings : [];
        $experimentId = is_numeric($settings['experiment_id'] ?? null) ? (int) $settings['experiment_id'] : null;

        if (! (bool) config('features.experiments.enabled', true) || $experimentId === null || $experimentId <= 0) {
            return $this->pickVariant($rule, $visitorId);
        }

        $experiment = Experiment::query()
            ->withoutTenancy()
            ->where('tenant_id', (int) $rule->tenant_id)
            ->whereKey($experimentId)
            ->whereIn('status', ['running', 'active', 'draft'])
            ->first();

        if (! $experiment instanceof Experiment) {
            return $this->pickVariant($rule, $visitorId);
        }

        $assignmentKey = $visitorId !== '' ? $visitorId : sha1($path.'|'.$source.'|'.$rule->id);

        $assignment = app(ExperimentService::class)->assign(
            experiment: $experiment,
            assignmentKey: $assignmentKey,
            visitorId: $visitorId !== '' ? $visitorId : null,
            meta: [
                'personalization_rule_id' => (int) $rule->id,
                'path' => $path,
                'source' => $source,
            ],
        );

        if ($assignment->is_holdout) {
            return null;
        }

        if (is_string($assignment->variant_key) && trim($assignment->variant_key) !== '') {
            $matched = $rule->variants->first(
                static fn (PersonalizationVariant $variant): bool => $variant->variant_key === $assignment->variant_key
            );

            if ($matched instanceof PersonalizationVariant) {
                return $matched;
            }
        }

        return $this->pickVariant($rule, $visitorId);
    }

    /**
     * Simulate which rule would match a context payload.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function simulateForRule(PersonalizationRule $rule, array $context): array
    {
        $path = $this->normalizePath($context['path'] ?? null);
        $utm = is_array($context['utm'] ?? null) ? $context['utm'] : [];
        $source = trim((string) ($context['source'] ?? ''));
        $device = trim((string) ($context['device'] ?? ''));
        $visitorId = trim((string) ($context['visitor_id'] ?? ''));

        $matchRules = is_array($rule->match_rules_json) ? $rule->match_rules_json : [];
        $matched = $this->matches($matchRules, $path, $source, $device, $utm);
        $variant = $matched ? $this->pickVariant($rule, $visitorId) : null;

        return [
            'matched' => $matched,
            'rule_id' => (int) $rule->id,
            'variant' => $variant !== null ? [
                'id' => (int) $variant->id,
                'key' => (string) $variant->variant_key,
                'patch' => $this->sanitizePatch($variant->changes_json),
            ] : null,
        ];
    }

    /**
     * @param array<string, mixed> $rules
     * @param array<string, mixed> $utm
     */
    private function matches(array $rules, string $path, string $source, string $device, array $utm): bool
    {
        $pathContains = $this->normalizeList($rules['path_contains'] ?? []);

        if ($pathContains !== [] && ! $this->containsAny($path, $pathContains)) {
            return false;
        }

        $sources = $this->normalizeList($rules['source'] ?? []);

        if ($sources !== [] && ! in_array(mb_strtolower($source), $sources, true)) {
            return false;
        }

        $devices = $this->normalizeList($rules['device'] ?? []);

        if ($devices !== [] && ! in_array(mb_strtolower($device), $devices, true)) {
            return false;
        }

        $utmSource = $this->normalizeList($rules['utm_source'] ?? []);
        $utmMedium = $this->normalizeList($rules['utm_medium'] ?? []);
        $utmCampaign = $this->normalizeList($rules['utm_campaign'] ?? []);

        if ($utmSource !== [] && ! in_array(mb_strtolower((string) ($utm['utm_source'] ?? '')), $utmSource, true)) {
            return false;
        }

        if ($utmMedium !== [] && ! in_array(mb_strtolower((string) ($utm['utm_medium'] ?? '')), $utmMedium, true)) {
            return false;
        }

        if ($utmCampaign !== [] && ! in_array(mb_strtolower((string) ($utm['utm_campaign'] ?? '')), $utmCampaign, true)) {
            return false;
        }

        return true;
    }

    private function pickVariant(PersonalizationRule $rule, string $visitorId): ?PersonalizationVariant
    {
        $variants = $rule->variants
            ->sortByDesc('is_control')
            ->sortBy('id')
            ->values();

        if ($variants->isEmpty()) {
            return null;
        }

        $totalWeight = max(1, (int) $variants->sum(static fn (PersonalizationVariant $variant): int => max(1, (int) $variant->weight)));

        $seed = $visitorId !== ''
            ? sprintf('%d|%s', $rule->id, $visitorId)
            : sprintf('%d|guest', $rule->id);

        $bucket = (int) (hexdec(substr(hash('sha256', $seed), 0, 8)) % $totalWeight);
        $cursor = 0;

        foreach ($variants as $variant) {
            $weight = max(1, (int) $variant->weight);
            $cursor += $weight;

            if ($bucket < $cursor) {
                return $variant;
            }
        }

        return $variants->last();
    }

    /**
     * @param mixed $rawPatch
     * @return list<array<string, mixed>>
     */
    private function sanitizePatch(mixed $rawPatch): array
    {
        $allowedActions = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            (array) config('features.personalization.allowed_actions', [])
        )));

        $allowlist = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            (array) config('features.personalization.selector_allowlist', [])
        )));

        $changes = is_array($rawPatch) ? $rawPatch : [];
        $safe = [];

        foreach ($changes as $change) {
            if (! is_array($change)) {
                continue;
            }

            $selector = trim((string) ($change['selector'] ?? ''));
            $action = trim((string) ($change['action'] ?? ''));

            if ($selector === '' || $action === '') {
                continue;
            }

            if (! in_array($action, $allowedActions, true)) {
                continue;
            }

            if (! $this->isSelectorAllowed($selector, $allowlist)) {
                continue;
            }

            $safe[] = [
                'selector' => $selector,
                'action' => $action,
                'value' => $change['value'] ?? null,
                'attr' => $change['attr'] ?? null,
            ];
        }

        return $safe;
    }

    /**
     * @param list<string> $allowlist
     */
    private function isSelectorAllowed(string $selector, array $allowlist): bool
    {
        $normalized = trim($selector);

        foreach ($allowlist as $prefix) {
            if ($prefix !== '' && str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function normalizeList(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(static fn (mixed $item): string => mb_strtolower(trim((string) $item)))
            ->filter(static fn (string $item): bool => $item !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function normalizePath(mixed $path): string
    {
        if (! is_string($path)) {
            return '';
        }

        $trimmed = trim($path);

        if ($trimmed === '') {
            return '';
        }

        $parsed = parse_url($trimmed, PHP_URL_PATH);

        if (is_string($parsed) && $parsed !== '') {
            return mb_strtolower($parsed);
        }

        return mb_strtolower($trimmed);
    }

    /**
     * @param list<string> $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        $normalized = mb_strtolower($haystack);

        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }
}
