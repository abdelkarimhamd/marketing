<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Arr;

class VariableRenderingService
{
    /**
     * Render template variables from context data.
     *
     * Supported syntax: {{first_name}}, {{company}}, {{lead.city}}.
     *
     * @param array<string, mixed> $variables
     */
    public function renderString(string $template, array $variables): string
    {
        $result = $this->renderStringWithMeta($template, $variables);

        return (string) ($result['rendered'] ?? '');
    }

    /**
     * Render one template string and return personalization debug metadata.
     *
     * Syntax additions:
     * - conditional block: {{#if city=Riyadh}}...{{else}}...{{/if}}
     * - localization block: {{#lang ar}}...{{/lang}}{{#lang en}}...{{/lang}}
     * - variable fallback: {{first_name|Customer}}
     *
     * @param array<string, mixed> $variables
     * @return array{rendered: string, meta: array<string, mixed>}
     */
    public function renderStringWithMeta(string $template, array $variables): array
    {
        $meta = $this->emptyRenderMeta($variables);

        $rendered = $this->renderLocalizationBlocks($template, $variables, $meta);
        $rendered = $this->renderConditionalBlocks($rendered, $variables, $meta);
        $rendered = $this->replaceVariableTokens($rendered, $variables, $meta);
        $rendered = $this->stripDanglingDirectiveTokens($rendered);

        return [
            'rendered' => $rendered,
            'meta' => $this->normalizeRenderMeta($meta),
        ];
    }

    /**
     * Render all string values in an array payload recursively.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    public function renderArray(array $payload, array $variables): array
    {
        $result = $this->renderArrayWithMeta($payload, $variables);

        return is_array($result['rendered'] ?? null) ? $result['rendered'] : [];
    }

    /**
     * Render array payload recursively and collect personalization metadata.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $variables
     * @return array{rendered: array<string, mixed>, meta: array<string, mixed>}
     */
    public function renderArrayWithMeta(array $payload, array $variables): array
    {
        $rendered = [];
        $meta = $this->emptyRenderMeta($variables);

        foreach ($payload as $key => $value) {
            if (is_string($value)) {
                $node = $this->renderStringWithMeta($value, $variables);
                $rendered[$key] = $node['rendered'];
                $meta = $this->mergeRenderMeta($meta, is_array($node['meta'] ?? null) ? $node['meta'] : []);
                continue;
            }

            if (is_array($value)) {
                $node = $this->renderArrayWithMeta($value, $variables);
                $rendered[$key] = $node['rendered'];
                $meta = $this->mergeRenderMeta($meta, is_array($node['meta'] ?? null) ? $node['meta'] : []);
                continue;
            }

            $rendered[$key] = $value;
        }

        return [
            'rendered' => $rendered,
            'meta' => $this->normalizeRenderMeta($meta),
        ];
    }

    /**
     * Create empty render metadata for one render pass.
     *
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    public function emptyRenderMeta(array $variables = []): array
    {
        return [
            'locale' => $this->resolveLocale($variables),
            'conditions' => [
                'evaluated' => 0,
                'matched' => 0,
                'unmatched' => 0,
            ],
            'localization' => [
                'evaluated' => 0,
                'matched' => 0,
                'unmatched' => 0,
            ],
            'fallbacks_used' => [],
            'missing_variables' => [],
        ];
    }

    /**
     * Merge two personalization metadata payloads.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    public function mergeRenderMeta(array $base, array $incoming): array
    {
        $merged = $this->emptyRenderMeta([]);

        $baseConditions = is_array($base['conditions'] ?? null) ? $base['conditions'] : [];
        $incomingConditions = is_array($incoming['conditions'] ?? null) ? $incoming['conditions'] : [];
        $baseLocalization = is_array($base['localization'] ?? null) ? $base['localization'] : [];
        $incomingLocalization = is_array($incoming['localization'] ?? null) ? $incoming['localization'] : [];

        $merged['locale'] = (string) ($incoming['locale'] ?? $base['locale'] ?? '');
        $merged['conditions'] = [
            'evaluated' => (int) ($baseConditions['evaluated'] ?? 0) + (int) ($incomingConditions['evaluated'] ?? 0),
            'matched' => (int) ($baseConditions['matched'] ?? 0) + (int) ($incomingConditions['matched'] ?? 0),
            'unmatched' => (int) ($baseConditions['unmatched'] ?? 0) + (int) ($incomingConditions['unmatched'] ?? 0),
        ];
        $merged['localization'] = [
            'evaluated' => (int) ($baseLocalization['evaluated'] ?? 0) + (int) ($incomingLocalization['evaluated'] ?? 0),
            'matched' => (int) ($baseLocalization['matched'] ?? 0) + (int) ($incomingLocalization['matched'] ?? 0),
            'unmatched' => (int) ($baseLocalization['unmatched'] ?? 0) + (int) ($incomingLocalization['unmatched'] ?? 0),
        ];

        $merged['fallbacks_used'] = array_merge(
            is_array($base['fallbacks_used'] ?? null) ? $base['fallbacks_used'] : [],
            is_array($incoming['fallbacks_used'] ?? null) ? $incoming['fallbacks_used'] : [],
        );
        $merged['missing_variables'] = array_merge(
            is_array($base['missing_variables'] ?? null) ? $base['missing_variables'] : [],
            is_array($incoming['missing_variables'] ?? null) ? $incoming['missing_variables'] : [],
        );

        return $this->normalizeRenderMeta($merged);
    }

    /**
     * Build default variable context from a lead model.
     *
     * @return array<string, mixed>
     */
    public function variablesFromLead(Lead $lead): array
    {
        $fullName = trim((string) ($lead->first_name ?? '').' '.(string) ($lead->last_name ?? ''));

        return [
            'id' => $lead->id,
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'full_name' => $fullName,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'company' => $lead->company,
            'title' => $lead->title,
            'city' => $lead->city,
            'country_code' => $lead->country_code,
            'interest' => $lead->interest,
            'service' => $lead->service,
            'status' => $lead->status,
            'source' => $lead->source,
            'score' => $lead->score,
            'timezone' => $lead->timezone,
            'locale' => $lead->locale,
            'language' => $this->localePrimary((string) ($lead->locale ?? '')),
            'lead' => $lead->toArray(),
        ];
    }

    /**
     * @param array<string, mixed> $variables
     * @param array<string, mixed> $meta
     */
    private function renderLocalizationBlocks(string $template, array $variables, array &$meta): string
    {
        $pattern = '/\{\{\s*#lang\s+([^}]+?)\s*\}\}(.*?)\{\{\s*\/lang\s*\}\}/is';
        $iterations = 0;

        while ($iterations < 50 && preg_match($pattern, $template) === 1) {
            $template = (string) preg_replace_callback(
                $pattern,
                function (array $matches) use ($variables, &$meta): string {
                    $localeExpression = (string) ($matches[1] ?? '');
                    $block = (string) ($matches[2] ?? '');
                    [$matchedContent, $fallbackContent] = $this->splitElseBlock($block);

                    $meta['localization']['evaluated'] = (int) ($meta['localization']['evaluated'] ?? 0) + 1;

                    if ($this->matchesLocale($localeExpression, $variables)) {
                        $meta['localization']['matched'] = (int) ($meta['localization']['matched'] ?? 0) + 1;

                        return $matchedContent;
                    }

                    $meta['localization']['unmatched'] = (int) ($meta['localization']['unmatched'] ?? 0) + 1;

                    return $fallbackContent;
                },
                $template
            );

            $iterations++;
        }

        return $template;
    }

    /**
     * @param array<string, mixed> $variables
     * @param array<string, mixed> $meta
     */
    private function renderConditionalBlocks(string $template, array $variables, array &$meta): string
    {
        $pattern = '/\{\{\s*#if\s+([^}]+?)\s*\}\}(.*?)\{\{\s*\/if\s*\}\}/is';
        $iterations = 0;

        while ($iterations < 50 && preg_match($pattern, $template) === 1) {
            $template = (string) preg_replace_callback(
                $pattern,
                function (array $matches) use ($variables, &$meta): string {
                    $expression = (string) ($matches[1] ?? '');
                    $block = (string) ($matches[2] ?? '');
                    [$matchedContent, $fallbackContent] = $this->splitElseBlock($block);

                    $meta['conditions']['evaluated'] = (int) ($meta['conditions']['evaluated'] ?? 0) + 1;

                    if ($this->evaluateCondition($expression, $variables)) {
                        $meta['conditions']['matched'] = (int) ($meta['conditions']['matched'] ?? 0) + 1;

                        return $matchedContent;
                    }

                    $meta['conditions']['unmatched'] = (int) ($meta['conditions']['unmatched'] ?? 0) + 1;

                    return $fallbackContent;
                },
                $template
            );

            $iterations++;
        }

        return $template;
    }

    /**
     * @param array<string, mixed> $variables
     * @param array<string, mixed> $meta
     */
    private function replaceVariableTokens(string $template, array $variables, array &$meta): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.-]+)(?:\s*\|\s*([^{}]+?))?\s*\}\}/',
            function (array $matches) use ($variables, &$meta): string {
                $key = (string) ($matches[1] ?? '');
                $fallback = array_key_exists(2, $matches)
                    ? $this->normalizeFallbackValue((string) ($matches[2] ?? ''))
                    : null;
                $value = Arr::get($variables, $key);

                if ($this->shouldUseFallback($value)) {
                    if ($fallback !== null) {
                        $meta['fallbacks_used'][] = [
                            'key' => $key,
                            'fallback' => $fallback,
                        ];

                        return $fallback;
                    }

                    $meta['missing_variables'][] = $key;

                    return '';
                }

                return (string) $value;
            },
            $template
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitElseBlock(string $block): array
    {
        $parts = preg_split('/\{\{\s*else\s*\}\}/i', $block, 2);

        if (! is_array($parts) || $parts === []) {
            return [$block, ''];
        }

        $matched = (string) ($parts[0] ?? '');
        $fallback = (string) ($parts[1] ?? '');

        return [$matched, $fallback];
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function matchesLocale(string $localeExpression, array $variables): bool
    {
        $locale = $this->resolveLocale($variables);
        $localePrimary = $this->localePrimary($locale);

        $allowed = collect(preg_split('/[,|]/', $localeExpression) ?: [])
            ->map(fn ($value): string => $this->normalizeLocaleToken((string) $value))
            ->filter()
            ->values();

        foreach ($allowed as $candidate) {
            if ($candidate === '*') {
                return true;
            }

            if ($candidate === $locale) {
                return true;
            }

            if ($this->localePrimary($candidate) === $localePrimary) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function evaluateCondition(string $expression, array $variables): bool
    {
        $expression = trim($expression);
        if ($expression === '') {
            return false;
        }

        if (preg_match('/^!\s*([a-zA-Z0-9_.-]+)$/', $expression, $match) === 1) {
            $value = Arr::get($variables, (string) ($match[1] ?? ''));

            return ! $this->isTruthy($value);
        }

        if (preg_match('/^([a-zA-Z0-9_.-]+)\s*(=|!=)\s*(.+)$/', $expression, $match) === 1) {
            $key = (string) ($match[1] ?? '');
            $operator = (string) ($match[2] ?? '=');
            $expected = $this->normalizeComparisonValue($match[3] ?? null);
            $actual = $this->normalizeComparisonValue(Arr::get($variables, $key));

            $isEqual = mb_strtolower($actual) === mb_strtolower($expected);

            return $operator === '!=' ? ! $isEqual : $isEqual;
        }

        if (preg_match('/^([a-zA-Z0-9_.-]+)$/', $expression, $match) === 1) {
            $value = Arr::get($variables, (string) ($match[1] ?? ''));

            return $this->isTruthy($value);
        }

        return false;
    }

    private function stripDanglingDirectiveTokens(string $template): string
    {
        $template = (string) preg_replace('/\{\{\s*#(?:if|lang)\b[^}]*\}\}/i', '', $template);
        $template = (string) preg_replace('/\{\{\s*\/(?:if|lang)\s*\}\}/i', '', $template);
        $template = (string) preg_replace('/\{\{\s*else\s*\}\}/i', '', $template);

        return $template;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function normalizeRenderMeta(array $meta): array
    {
        $fallbacks = collect(is_array($meta['fallbacks_used'] ?? null) ? $meta['fallbacks_used'] : [])
            ->filter(fn ($entry): bool => is_array($entry) && isset($entry['key']))
            ->map(function (array $entry): array {
                return [
                    'key' => (string) ($entry['key'] ?? ''),
                    'fallback' => (string) ($entry['fallback'] ?? ''),
                ];
            })
            ->unique(fn (array $entry): string => $entry['key'].'|'.$entry['fallback'])
            ->values()
            ->all();

        $missing = collect(is_array($meta['missing_variables'] ?? null) ? $meta['missing_variables'] : [])
            ->map(fn ($entry): string => (string) $entry)
            ->filter(fn (string $entry): bool => trim($entry) !== '')
            ->unique()
            ->values()
            ->all();

        $conditions = is_array($meta['conditions'] ?? null) ? $meta['conditions'] : [];
        $localization = is_array($meta['localization'] ?? null) ? $meta['localization'] : [];

        return [
            'locale' => (string) ($meta['locale'] ?? ''),
            'conditions' => [
                'evaluated' => (int) ($conditions['evaluated'] ?? 0),
                'matched' => (int) ($conditions['matched'] ?? 0),
                'unmatched' => (int) ($conditions['unmatched'] ?? 0),
            ],
            'localization' => [
                'evaluated' => (int) ($localization['evaluated'] ?? 0),
                'matched' => (int) ($localization['matched'] ?? 0),
                'unmatched' => (int) ($localization['unmatched'] ?? 0),
            ],
            'fallbacks_used' => $fallbacks,
            'missing_variables' => $missing,
        ];
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function resolveLocale(array $variables): string
    {
        $candidates = [
            Arr::get($variables, 'locale'),
            Arr::get($variables, 'lead.locale'),
            Arr::get($variables, 'language'),
            Arr::get($variables, 'lead.language'),
            'en',
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $normalized = $this->normalizeLocaleToken($candidate);

            if ($normalized !== '') {
                return $normalized;
            }
        }

        return 'en';
    }

    private function normalizeLocaleToken(string $locale): string
    {
        $locale = mb_strtolower(trim(str_replace('_', '-', $locale)));
        $locale = preg_replace('/[^a-z0-9-]/', '', $locale);

        return is_string($locale) ? $locale : '';
    }

    private function localePrimary(string $locale): string
    {
        $normalized = $this->normalizeLocaleToken($locale);
        if ($normalized === '') {
            return '';
        }

        $parts = explode('-', $normalized, 2);

        return (string) ($parts[0] ?? '');
    }

    private function normalizeComparisonValue(mixed $value): string
    {
        if (is_string($value)) {
            return $this->stripWrappingQuotes(trim($value));
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return '';
    }

    private function normalizeFallbackValue(string $value): string
    {
        return $this->stripWrappingQuotes(trim($value));
    }

    private function stripWrappingQuotes(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    private function shouldUseFallback(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_array($value) || is_object($value)) {
            return true;
        }

        return is_string($value) && trim($value) === '';
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float) $value !== 0.0;
        }

        if (is_string($value)) {
            $normalized = mb_strtolower(trim($value));

            return $normalized !== '' && ! in_array($normalized, ['0', 'false', 'no', 'off', 'null'], true);
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return $value !== null;
    }
}
