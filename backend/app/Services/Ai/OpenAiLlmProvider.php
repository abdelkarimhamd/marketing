<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;

class OpenAiLlmProvider implements LlmProviderInterface
{
    public function summarize(array $context): string
    {
        $prompt = $this->buildSummaryPrompt($context);
        $response = $this->chat($prompt);

        return trim($response) !== '' ? trim($response) : 'Summary could not be generated.';
    }

    public function recommend(array $context): array
    {
        $prompt = $this->buildRecommendationPrompt($context);
        $response = $this->chat($prompt);

        $decoded = json_decode($response, true);

        if (! is_array($decoded)) {
            return app(NullLlmProvider::class)->recommend($context);
        }

        $items = is_array($decoded['items'] ?? null) ? $decoded['items'] : $decoded;

        if (! is_array($items)) {
            return app(NullLlmProvider::class)->recommend($context);
        }

        $result = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = trim((string) ($item['type'] ?? ''));
            $score = (float) ($item['score'] ?? 0);

            if ($type === '') {
                continue;
            }

            $result[] = [
                'type' => $type,
                'score' => max(0.0, min(1.0, $score)),
                'payload' => is_array($item['payload'] ?? null) ? $item['payload'] : [
                    'reason' => (string) ($item['reason'] ?? ''),
                ],
            ];
        }

        return $result !== [] ? $result : app(NullLlmProvider::class)->recommend($context);
    }

    public function key(): string
    {
        return 'openai';
    }

    private function chat(string $prompt): string
    {
        $apiKey = trim((string) config('services.openai.api_key', ''));

        if ($apiKey === '') {
            return '';
        }

        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/');
        $model = (string) config('services.openai.model', 'gpt-4o-mini');

        $response = Http::withToken($apiKey)
            ->timeout(30)
            ->post($baseUrl.'/chat/completions', [
                'model' => $model,
                'temperature' => 0.2,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a concise sales copilot. Follow requested output format exactly.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
            ]);

        if (! $response->successful()) {
            return '';
        }

        $json = is_array($response->json()) ? $response->json() : [];
        $content = data_get($json, 'choices.0.message.content');

        return is_string($content) ? $content : '';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function buildSummaryPrompt(array $context): string
    {
        return "Summarize this lead in under 120 words for a sales rep.\n"
            .'Context JSON: '.json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function buildRecommendationPrompt(array $context): string
    {
        return "Return JSON only with top next actions. Format: {\"items\":[{\"type\":\"call|email|whatsapp|wait\",\"score\":0-1,\"payload\":{\"reason\":\"...\"}}]}\n"
            .'Context JSON: '.json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
