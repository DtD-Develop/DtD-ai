<?php

namespace App\Services\Ai\LLM;

/**
 * LLMRouter
 *
 * High-level routing interface for Large Language Model (LLM) calls.
 *
 * Instead of binding application code directly to a specific engine
 * (e.g. local GPU model via Ollama, or cloud LLM like Gemini),
 * we route all generation requests through this interface.
 *
 * The router can:
 * - Inspect the "task" type and metadata.
 * - Decide which concrete engine to use (local / cloud / etc.).
 * - Apply per-task policies (temperature, max tokens, etc. in future).
 *
 * Typical usage:
 *
 *   $answer = $llmRouter->generate([
 *       'prompt'        => 'Your prompt here',
 *       'system_prompt' => 'You are a helpful assistant',
 *       'temperature'   => 0.2,
 *       'max_tokens'    => 512,
 *       'metadata'      => [
 *           'task' => 'kb_summary',
 *           'job'  => 'AnalyzeKbFileJob',
 *       ],
 *   ]);
 */
interface LLMRouter
{
    /**
     * Route a generation request to an appropriate LLM engine
     * (local GPU model, Gemini, etc.) based on metadata / task type.
     *
     * The payload structure is intentionally similar to LLMAdapter so
     * that existing call sites can be migrated easily.
     *
     * Expected payload keys:
     * - prompt        (string)  : required, main user prompt or full prompt text
     * - system_prompt (string)  : optional, system/instruction message
     * - temperature   (float)   : optional, sampling temperature
     * - max_tokens    (int)     : optional, limit for generated tokens
     * - metadata      (array)   : optional, includes "task" and other context
     *
     * Common metadata keys:
     * - task   : high-level task identifier (e.g. "chat", "kb_summary",
     *            "kb_auto_tag", "title_generation", "training_to_kb", etc.)
     * - job    : origin job name (e.g. "AnalyzeKbFileJob")
     * - source : origin controller / endpoint (optional)
     *
     * @param  array{
     *   prompt: string,
     *   system_prompt?: string,
     *   temperature?: float,
     *   max_tokens?: int,
     *   metadata?: array<string,mixed>
     * }  $payload
     *
     * @return string  The generated text response from the selected LLM.
     */
    public function generate(array $payload): string;
}
