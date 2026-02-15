<?php

namespace App\Services\Ai;

class NullLlmProvider implements LlmProviderInterface
{
    public function summarize(array $context): string
    {
        $lead = is_array($context['lead'] ?? null) ? $context['lead'] : [];
        $activities = is_array($context['activities'] ?? null) ? $context['activities'] : [];
        $messages = is_array($context['messages'] ?? null) ? $context['messages'] : [];

        $name = trim((string) ($lead['name'] ?? 'Lead'));
        $status = trim((string) ($lead['status'] ?? 'new'));
        $score = (int) ($lead['score'] ?? 0);

        return sprintf(
            '%s is currently in %s status with score %d. %d activities and %d messages were analyzed.',
            $name,
            $status,
            $score,
            count($activities),
            count($messages),
        );
    }

    public function recommend(array $context): array
    {
        $lead = is_array($context['lead'] ?? null) ? $context['lead'] : [];
        $score = (int) ($lead['score'] ?? 0);
        $hasPhone = trim((string) ($lead['phone'] ?? '')) !== '';
        $hasEmail = trim((string) ($lead['email'] ?? '')) !== '';

        $result = [];

        if ($hasPhone) {
            $result[] = [
                'type' => 'call',
                'score' => $score >= 60 ? 0.92 : 0.75,
                'payload' => [
                    'reason' => 'Phone is available and engagement score supports direct outreach.',
                    'channel' => 'phone',
                ],
            ];
        }

        if ($hasEmail) {
            $result[] = [
                'type' => 'email',
                'score' => $score >= 60 ? 0.87 : 0.7,
                'payload' => [
                    'reason' => 'Email follow-up can reinforce the proposal and capture replies.',
                    'channel' => 'email',
                ],
            ];
        }

        $result[] = [
            'type' => 'wait',
            'score' => $score >= 70 ? 0.3 : 0.55,
            'payload' => [
                'reason' => 'Allow a short delay before next touch if the lead just received outreach.',
            ],
        ];

        usort($result, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $result;
    }

    public function key(): string
    {
        return 'null';
    }
}
