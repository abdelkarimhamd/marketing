<?php

namespace App\Services\Ai;

class LlmManager
{
    public function provider(): LlmProviderInterface
    {
        if (! (bool) config('features.ai.enabled', false)) {
            return app(NullLlmProvider::class);
        }

        $provider = trim(mb_strtolower((string) config('features.ai.provider', 'null')));

        if ($provider === 'openai' && trim((string) config('services.openai.api_key', '')) !== '') {
            return app(OpenAiLlmProvider::class);
        }

        return app(NullLlmProvider::class);
    }
}
