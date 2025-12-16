DtD-ai\backend\app\Services\Ai\LLM\LocalAdapter.php
<?php

namespace App\Services\Ai\LLM;

use App\Services\Ai\LLM\LLMAdapter;
use App\Services\Ai\LLM\OllamaService;

/**
 * LocalAdapter
 *
 * LLMAdapter implementation that delegates all generation calls
 * to the local OllamaService (running inside the infra stack).
 *
 * This is the "local" engine used when LLM_DRIVER=local.
 */
class LocalAdapter implements LLMAdapter
{
    public function __construct(
        protected OllamaService $ollama,
    ) {
    }

    /**
     * Generate a completion using the local Ollama model.
     *
     * @param  array{
     *   prompt: string,
     *   system_prompt?: string,
     *   temperature?: float,
     *   max_tokens?: int,
     *   metadata?: array<string,mixed>
     * }  $payload
     *
     * @return string
     */
    public function generate(array $payload): string
    {
        $prompt = $this->buildPrompt($payload);

        // For now we ignore temperature / max_tokens because the current
        // OllamaService::generate() wrapper does not expose them yet.
        // You can extend OllamaService and this adapter later to pass them through.
        return $this->ollama->generate($prompt);
    }

    /**
     * Build the final prompt string sent to Ollama from the generic payload.
     *
     * - If system_prompt is present, prepend it above the main prompt.
     * - Otherwise just return the user prompt.
     */
    protected function buildPrompt(array $payload): string
    {
        $userPrompt = (string) ($payload['prompt'] ?? '');
        $systemPrompt = isset($payload['system_prompt'])
            ? (string) $payload['system_prompt']
            : '';

        if ($systemPrompt !== '') {
            return $systemPrompt . "\n\n" . $userPrompt;
        }

        return $userPrompt;
    }
}
