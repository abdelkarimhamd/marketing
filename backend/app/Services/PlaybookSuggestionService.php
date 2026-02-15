<?php

namespace App\Services;

use App\Models\Playbook;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PlaybookSuggestionService
{
    /**
     * Rank playbooks for contextual suggestions.
     *
     * @param Collection<int, Playbook> $playbooks
     * @return Collection<int, array<string, mixed>>
     */
    public function rank(Collection $playbooks, ?string $industry, ?string $stage, ?string $channel, ?string $query): Collection
    {
        $normalizedIndustry = $this->normalize($industry);
        $normalizedStage = $this->normalize($stage);
        $normalizedChannel = $this->normalize($channel);
        $normalizedQuery = $this->normalize($query);

        return $playbooks
            ->map(function (Playbook $playbook) use (
                $normalizedIndustry,
                $normalizedStage,
                $normalizedChannel,
                $normalizedQuery
            ): array {
                $score = 0;
                $playbookIndustry = $this->normalize($playbook->industry);
                $playbookStage = $this->normalize($playbook->stage);
                $playbookChannel = $this->normalize($playbook->channel);

                if ($normalizedIndustry !== null && $playbookIndustry !== null) {
                    $score += $playbookIndustry === $normalizedIndustry ? 50 : 0;
                } elseif ($playbookIndustry !== null) {
                    $score += 10;
                }

                if ($normalizedStage !== null && $playbookStage !== null) {
                    $score += $playbookStage === $normalizedStage ? 30 : 0;
                } elseif ($playbookStage === null) {
                    $score += 8;
                }

                if ($normalizedChannel !== null && $playbookChannel !== null) {
                    $score += $playbookChannel === $normalizedChannel ? 20 : 0;
                } elseif ($playbookChannel === null) {
                    $score += 6;
                }

                if ($normalizedQuery !== null) {
                    $haystack = $this->collectHaystack($playbook);

                    if (Str::contains($haystack, $normalizedQuery, ignoreCase: true)) {
                        $score += 25;
                    }
                }

                return [
                    'playbook' => $playbook,
                    'score' => $score,
                ];
            })
            ->sortByDesc('score')
            ->sortByDesc(fn (array $row): string => (string) optional($row['playbook']->updated_at)->toISOString())
            ->values();
    }

    private function collectHaystack(Playbook $playbook): string
    {
        $parts = [
            (string) $playbook->name,
            (string) $playbook->industry,
            (string) ($playbook->stage ?? ''),
            (string) ($playbook->channel ?? ''),
        ];

        foreach ((array) $playbook->scripts as $script) {
            $parts[] = is_string($script) ? $script : '';
        }

        foreach ((array) $playbook->objections as $objection) {
            if (! is_array($objection)) {
                continue;
            }

            $parts[] = (string) ($objection['objection'] ?? '');
            $parts[] = (string) ($objection['response'] ?? '');
        }

        foreach ((array) $playbook->templates as $template) {
            if (! is_array($template)) {
                continue;
            }

            $parts[] = (string) ($template['title'] ?? '');
            $parts[] = (string) ($template['content'] ?? '');
        }

        return implode(' ', array_filter($parts, static fn (string $part): bool => trim($part) !== ''));
    }

    private function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = Str::lower(trim($value));

        return $normalized !== '' ? $normalized : null;
    }
}
