<?php

namespace App\Services;

use App\Models\AiInteraction;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;

class AiAssistantService
{
    /**
     * Generate lightweight campaign copy variants.
     *
     * @param array<string, mixed> $context
     * @return array{subject_lines: list<string>, body_variants: list<string>}
     */
    public function campaignCopy(array $context, int $tenantId, ?User $user = null): array
    {
        $offer = trim((string) ($context['offer'] ?? 'our latest service'));
        $audience = trim((string) ($context['audience'] ?? 'your team'));
        $tone = trim((string) ($context['tone'] ?? 'professional'));
        $channel = trim((string) ($context['channel'] ?? 'email'));

        $subjectLines = [
            "Quick idea for {$audience}",
            "{$audience}: improve results with {$offer}",
            "A {$tone} approach to {$offer}",
        ];

        $bodyVariants = [
            "Hi {{first_name}}, we prepared {$offer} for {$audience}. Reply to book a short call.",
            "Hello {{first_name}}, this {$tone} campaign is tailored for {$audience}. Interested?",
            "Hi {{first_name}}, we can help with {$offer}. Would you like details?",
        ];

        $this->store(
            tenantId: $tenantId,
            user: $user,
            type: 'campaign_copy',
            prompt: json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null,
            response: json_encode([
                'subject_lines' => $subjectLines,
                'body_variants' => $bodyVariants,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null,
            meta: ['channel' => $channel],
        );

        return [
            'subject_lines' => $subjectLines,
            'body_variants' => $bodyVariants,
        ];
    }

    /**
     * Classify lead intent for smart routing.
     *
     * @return array{intent: string, confidence: float}
     */
    public function classifyLeadIntent(Lead $lead, ?User $user = null): array
    {
        $text = mb_strtolower(implode(' ', array_filter([
            $lead->interest,
            $lead->service,
            (string) data_get($lead->meta, 'message'),
        ])));

        $map = [
            'solar' => 'solar',
            'crm' => 'crm',
            'automation' => 'automation',
            'ads' => 'marketing_ads',
            'whatsapp' => 'whatsapp',
            'support' => 'support',
        ];

        $intent = 'general';
        $confidence = 0.45;

        foreach ($map as $keyword => $resolvedIntent) {
            if (str_contains($text, $keyword)) {
                $intent = $resolvedIntent;
                $confidence = 0.82;
                break;
            }
        }

        $this->store(
            tenantId: (int) $lead->tenant_id,
            user: $user,
            lead: $lead,
            type: 'intent_classification',
            prompt: $text,
            response: $intent,
            confidence: $confidence,
        );

        return [
            'intent' => $intent,
            'confidence' => $confidence,
        ];
    }

    /**
     * Suggest reply text and sentiment label for inbox.
     *
     * @return array{sentiment: string, suggestions: list<string>}
     */
    public function replySuggestions(Message $message, ?User $user = null): array
    {
        $text = mb_strtolower((string) $message->body);
        $sentiment = 'neutral';

        if (str_contains($text, 'not interested') || str_contains($text, 'stop') || str_contains($text, 'unsubscribe')) {
            $sentiment = 'negative';
        } elseif (str_contains($text, 'yes') || str_contains($text, 'interested') || str_contains($text, 'price')) {
            $sentiment = 'positive';
        }

        $suggestions = match ($sentiment) {
            'positive' => [
                'Great, I can share pricing and next steps right away.',
                'Perfect, would tomorrow work for a short call?',
            ],
            'negative' => [
                'Understood, we will stop messages for this channel.',
                'Thanks for the update. If you need us later, reply anytime.',
            ],
            default => [
                'Thanks for your reply. Could you share your preferred time?',
                'Happy to help. What is your main priority right now?',
            ],
        };

        $this->store(
            tenantId: (int) $message->tenant_id,
            user: $user,
            lead: $message->lead,
            type: 'reply_suggestions',
            prompt: (string) $message->body,
            response: json_encode($suggestions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null,
            sentiment: $sentiment,
            meta: ['message_id' => $message->id],
        );

        return [
            'sentiment' => $sentiment,
            'suggestions' => $suggestions,
        ];
    }

    /**
     * Persist AI interaction for auditing.
     *
     * @param array<string, mixed>|null $meta
     */
    private function store(
        int $tenantId,
        ?User $user,
        ?Lead $lead = null,
        ?string $type = null,
        ?string $prompt = null,
        ?string $response = null,
        ?float $confidence = null,
        ?string $sentiment = null,
        ?array $meta = null
    ): void {
        AiInteraction::query()->withoutTenancy()->create([
            'tenant_id' => $tenantId,
            'user_id' => $user?->id,
            'lead_id' => $lead?->id,
            'campaign_id' => null,
            'type' => $type ?: 'unknown',
            'prompt' => $prompt,
            'response' => $response,
            'model' => 'heuristic-v1',
            'confidence' => $confidence,
            'sentiment' => $sentiment,
            'meta' => $meta ?? [],
        ]);
    }
}

