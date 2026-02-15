<?php

namespace App\Services\Ai;

interface LlmProviderInterface
{
    /**
     * Summarize lead context.
     *
     * @param array<string, mixed> $context
     */
    public function summarize(array $context): string;

    /**
     * Suggest next best actions.
     *
     * @param array<string, mixed> $context
     * @return list<array{type:string,score:float,payload:array<string,mixed>}>
     */
    public function recommend(array $context): array;

    /**
     * Provider key.
     */
    public function key(): string;
}
