<?php

namespace App\Services\Ai\LLM;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GeminiAdapter
 *
 * Stub implementation of LLMAdapter for Google Gemini.
 *
 * Notes:
 * - This is a minimal, non-production-ready adapter.
 * - You must adjust the request/response format according to
 *   the actual Gemini API version and client you use.
 * - It is designed so that the rest of the app can switch between
 *   "local" (Ollama) and "gemini" via configuration only.
 */
class GeminiAdapter implements LLMAdapter
{
    protected string $apiKey;
    protected string $model;
    protected string $endpoint;

    public function __construct()
    {
        // These should be configured in config/ai.php and .env
        $this->apiKey = (string) config("ai.gemini.api_key", "");
        $this->model = (string) config("ai.gemini.model", "gemini-1.5-pro");
        $this->endpoint = (string) config(
            "ai.gemini.endpoint",
            "https://generativelanguage.googleapis.com/v1beta/models",
        );
    }

    /**
     * Generate a completion using Gemini.
     *
     * IMPORTANT:
     * - The request/response structure here is intentionally generic.
     * - Adjust the payload and JSON parsing to match the exact Gemini
     *   REST API or SDK that you are using.
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
        $prompt = (string) ($payload["prompt"] ?? "");
        $systemPrompt = isset($payload["system_prompt"])
            ? (string) $payload["system_prompt"]
            : "";

        $temperature = isset($payload["temperature"])
            ? (float) $payload["temperature"]
            : 0.2;

        $maxTokens = isset($payload["max_tokens"])
            ? (int) $payload["max_tokens"]
            : 512;

        if ($prompt === "") {
            return "";
        }

        if ($this->apiKey === "") {
            Log::warning("GeminiAdapter: GEMINI_API_KEY is empty");

            return "";
        }

        // Build full prompt text (system + user) as a simple fallback.
        $fullPrompt = $this->buildPrompt($systemPrompt, $prompt);

        try {
            // Example REST call layout (adjust to actual Gemini API)
            $url =
                rtrim($this->endpoint, "/") .
                "/" .
                $this->model .
                ":generateContent";

            $body = [
                "contents" => [
                    [
                        "parts" => [
                            [
                                "text" => $fullPrompt,
                            ],
                        ],
                    ],
                ],
                "generationConfig" => [
                    "temperature" => $temperature,
                    "maxOutputTokens" => $maxTokens,
                ],
            ];

            $response = Http::withHeaders([
                "Content-Type" => "application/json",
                "x-goog-api-key" => $this->apiKey,
            ])->post($url, $body);

            if ($response->failed()) {
                Log::error("GeminiAdapter: HTTP request failed", [
                    "status" => $response->status(),
                    "body" => $response->body(),
                ]);

                return "";
            }

            $data = $response->json();

            /**
             * NOTE:
             * The following extraction is a placeholder and must be
             * adapted to the actual Gemini API response shape you get.
             *
             * For example (old style):
             *   $data['candidates'][0]['content']['parts'][0]['text'] ?? ''
             */
            $text = $this->extractTextFromResponse($data);

            return $text ?? "";
        } catch (\Throwable $e) {
            Log::error("GeminiAdapter: exception during request", [
                "error" => $e->getMessage(),
            ]);

            return "";
        }
    }

    /**
     * Build final prompt from system and user prompts.
     */
    protected function buildPrompt(
        string $systemPrompt,
        string $userPrompt,
    ): string {
        if ($systemPrompt !== "") {
            return $systemPrompt . "\n\n" . $userPrompt;
        }

        return $userPrompt;
    }

    /**
     * Best-effort extraction of text from a Gemini response.
     *
     * @param  array<string,mixed>|null  $data
     * @return string|null
     */
    protected function extractTextFromResponse(?array $data): ?string
    {
        if (!is_array($data)) {
            return null;
        }

        // This is a common structure in many Gemini examples, but you MUST
        // confirm against the actual API docs for your chosen version.
        $candidates = $data["candidates"] ?? null;

        if (!is_array($candidates) || empty($candidates)) {
            return null;
        }

        $first = $candidates[0] ?? null;

        if (!is_array($first)) {
            return null;
        }

        $content = $first["content"] ?? null;

        if (!is_array($content)) {
            return null;
        }

        $parts = $content["parts"] ?? null;

        if (!is_array($parts) || empty($parts)) {
            return null;
        }

        $firstPart = $parts[0] ?? null;

        if (is_array($firstPart) && isset($firstPart["text"])) {
            return (string) $firstPart["text"];
        }

        return null;
    }
}
