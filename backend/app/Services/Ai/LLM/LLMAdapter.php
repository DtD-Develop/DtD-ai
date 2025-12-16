<?php

namespace App\Services\Ai\LLM;

/**
 * Interface LLMAdapter
 *
 * Abstraction layer for any Large Language Model (LLM) provider used by the AI Platform.
 * This allows the backend to switch between different engines (e.g. Gemini, Ollama/local)
 * without changing business logic.
 *
 * Typical usage:
 *
 *  $answer = $llmAdapter->generate([
 *      'prompt'        => 'Your prompt here',
 *      'system_prompt' => 'You are a helpful assistant', // optional
 *      'temperature'   => 0.2,                           // optional
 *      'max_tokens'    => 512,                           // optional
 *      'metadata'      => ['conversation_id' => 123],    // optional
 *  ]);
 */
interface LLMAdapter
{
    /**
     * Generate a completion from the underlying LLM.
     *
     * The payload is intentionally generic so that different providers
     * can map it to their own request schema.
     *
     * Expected payload keys (all except "prompt" are optional):
     *
     * - prompt        (string)  : The main user prompt or full prompt text.
     * - system_prompt (string)  : System / instruction message to prepend.
     * - temperature   (float)   : Sampling temperature (0.0 – 1.0+).
     * - max_tokens    (int)     : Maximum number of tokens to generate.
     * - metadata      (array)   : Arbitrary metadata (for logging, tracing, etc.)
     *
     * @param  array{
     *   prompt: string,
     *   system_prompt?: string,
     *   temperature?: float,
     *   max_tokens?: int,
     *   metadata?: array<string,mixed>
     * }  $payload
     *
     * @return string  The generated text response from the LLM.
     */
    public function generate(array $payload): string;
}
