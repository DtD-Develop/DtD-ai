<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

class OllamaService
{
    protected string $baseUrl;
    protected string $model;
    protected ?LoggerInterface $logger;

    public function __construct(LoggerInterface $logger = null)
    {
        $this->baseUrl = rtrim(env("OLLAMA_URL", "http://ollama:11434"), "/");
        $this->model = env("OLLAMA_MODEL", "llama3.1:8b");
        $this->logger = $logger;
    }

    /**
     * Get embedding vector for a text.
     * Returns array of floats or null on failure.
     *
     * Prioritizes OPENAI_API_KEY if present (fallback), otherwise tries Ollama embed endpoint.
     */
    public function getEmbedding(string $text): ?array
    {
        $openaiKey = env("OPENAI_API_KEY");

        if (!empty($openaiKey)) {
            try {
                $res = Http::withHeaders([
                    "Authorization" => "Bearer " . $openaiKey,
                    "Content-Type" => "application/json",
                ])->post("https://api.openai.com/v1/embeddings", [
                    "model" => env(
                        "OPENAI_EMBED_MODEL",
                        "text-embedding-3-small",
                    ),
                    "input" => $text,
                ]);

                if ($res->ok()) {
                    $json = $res->json();
                    if (isset($json["data"][0]["embedding"])) {
                        return $json["data"][0]["embedding"];
                    }
                } else {
                    $this->logger?->warning("OpenAI embed failed", [
                        "status" => $res->status(),
                        "body" => $res->body(),
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger?->error("OpenAI embed error", [
                    "err" => $e->getMessage(),
                ]);
            }
            return null;
        }

        // Ollama embedding endpoint (some setups expose /embed or /api/embed)
        // Try common endpoints until success.
        $endpoints = [
            $this->baseUrl . "/embed",
            $this->baseUrl . "/api/embed",
            $this->baseUrl . "/api/embeddings",
        ];

        foreach ($endpoints as $url) {
            try {
                $res = Http::post($url, [
                    "model" => $this->model,
                    "input" => $text,
                ]);

                if ($res->ok()) {
                    $json = $res->json();
                    if (
                        isset($json["embedding"]) &&
                        is_array($json["embedding"])
                    ) {
                        return $json["embedding"];
                    }
                    if (isset($json["data"][0]["embedding"])) {
                        return $json["data"][0]["embedding"];
                    }
                }
            } catch (\Throwable $e) {
                $this->logger?->debug("Ollama embed attempt failed", [
                    "url" => $url,
                    "err" => $e->getMessage(),
                ]);
            }
        }

        $this->logger?->warning(
            "No embedding available (Ollama/OpenAI not responding or endpoint mismatch).",
        );
        return null;
    }

    /**
     * Generate text from a prompt using Ollama generate API.
     * Return raw response string or fallback message.
     */
    public function generate(string $prompt, array $options = []): string
    {
        // options: ['stream' => false, 'max_tokens' => int, ...] - passed to API if needed
        $url = rtrim($this->baseUrl, "/") . "/api/generate";

        $payload = array_merge(
            [
                "model" => $this->model,
                "prompt" => $prompt,
                "stream" => false,
            ],
            $options,
        );

        try {
            $res = Http::timeout(30)->post($url, $payload);

            if ($res->failed()) {
                $this->logger?->error("Ollama generate failed", [
                    "status" => $res->status(),
                    "body" => $res->body(),
                ]);
                return "I'm sorry, I cannot answer right now.";
            }

            $json = $res->json();

            // support different response formats
            if (isset($json["response"])) {
                return is_string($json["response"])
                    ? $json["response"]
                    : json_encode($json["response"]);
            }
            if (isset($json["text"])) {
                return $json["text"];
            }
            // Some Ollama returns choices/message content
            if (isset($json["choices"][0]["message"]["content"])) {
                return $json["choices"][0]["message"]["content"];
            }

            // fallback: return body text
            $body = $res->body();
            return $body ?: "No response from LLM.";
        } catch (\Throwable $e) {
            $this->logger?->error("Ollama generate exception", [
                "err" => $e->getMessage(),
            ]);
            return "Error communicating with language model.";
        }
    }

    /**
     * Convenience: chat-like wrapper that accepts messages array (role/content) and generates.
     * It will join messages into a single prompt.
     */
    public function chatFromMessages(
        array $messages,
        array $options = [],
    ): string {
        $assembled = "";
        foreach ($messages as $m) {
            $role = $m["role"] ?? "user";
            $content = $m["content"] ?? "";
            $assembled .= strtoupper($role) . ": " . $content . "\n\n";
        }

        $prompt =
            "You are a helpful assistant. Use the following conversation and answer the last user message.\n\n" .
            $assembled;
        return $this->generate($prompt, $options);
    }

    /**
     * Summarize helper (optional) - uses generate
     */
    public function summarize(string $text, int $maxSentences = 3): string
    {
        $prompt =
            "Summarize the following text into {$maxSentences} sentences. Keep it concise and factual:\n\n" .
            $text;
        return $this->generate($prompt, ["stream" => false]);
    }
}
