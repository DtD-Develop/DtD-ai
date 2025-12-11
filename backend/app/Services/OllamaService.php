<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OllamaService
{
    protected string $baseUrl;
    protected string $model;

    public function __construct()
    {
        $this->baseUrl = rtrim(env("OLLAMA_URL", "http://ollama:11434"), "/");
        $this->model = env("OLLAMA_MODEL", "llama3.1:8b");
    }

    /**
     * Get embedding vector for a text.
     * Returns array of floats or null on failure.
     *
     * NOTES:
     * - If you run Ollama locally, adjust OLLAMA_URL and OLLAMA_MODEL in .env
     * - Alternatively, set OPENAI_API_KEY in env to use OpenAI embeddings fallback.
     */
    public function getEmbedding(string $text): ?array
    {
        // prefer OpenAI if configured
        $openaiKey = env("OPENAI_API_KEY");
        if (!empty($openaiKey)) {
            $res = Http::withHeaders([
                "Authorization" => "Bearer {$openaiKey}",
                "Content-Type" => "application/json",
            ])->post("https://api.openai.com/v1/embeddings", [
                "model" => env("OPENAI_EMBED_MODEL", "text-embedding-3-small"),
                "input" => $text,
            ]);

            if ($res->ok()) {
                $json = $res->json();
                if (isset($json["data"][0]["embedding"])) {
                    return $json["data"][0]["embedding"];
                }
            }
            return null;
        }

        // Ollama embedding: many setups expose an embedding endpoint at /embed or similar.
        // Adjust path according to your Ollama setup. Here we try a common pattern:
        try {
            $url = $this->baseUrl . "/embed";
            $payload = [
                "model" => $this->model,
                "input" => $text,
            ];
            $res = Http::post($url, $payload);
            if ($res->ok()) {
                $json = $res->json();
                // try common keys
                if (isset($json["embedding"])) {
                    return $json["embedding"];
                }
                if (isset($json["data"][0]["embedding"])) {
                    return $json["data"][0]["embedding"];
                }
            }
        } catch (\Throwable $e) {
            // swallow - return null
        }

        return null;
    }

    /**
     * Summarize a text block. Return short summary string.
     * Uses Ollama (completion) or OpenAI chat/completion as fallback.
     */
    public function summarizeText(string $text, int $maxTokens = 256): string
    {
        $openaiKey = env("OPENAI_API_KEY");
        if (!empty($openaiKey)) {
            // use OpenAI chat completions
            $prompt =
                "Summarize the following text into a short paragraph (Thai/English as in the content):\n\n" .
                $text;
            $res = Http::withHeaders([
                "Authorization" => "Bearer {$openaiKey}",
                "Content-Type" => "application/json",
            ])->post("https://api.openai.com/v1/chat/completions", [
                "model" => env("OPENAI_SUMMARY_MODEL", "gpt-4o-mini"),
                "messages" => [["role" => "user", "content" => $prompt]],
                "max_tokens" => $maxTokens,
                "temperature" => 0.2,
            ]);
            if ($res->ok()) {
                $json = $res->json();
                return $json["choices"][0]["message"]["content"] ?? "";
            }
            return "";
        }

        // Ollama fallback: call generate endpoint
        try {
            $prompt =
                "Summarize the following text into 2-4 concise sentences:\n\n" .
                $text;
            $url = $this->baseUrl . "/api/generate"; // adjust if your Ollama uses different path
            $res = Http::post($url, [
                "model" => $this->model,
                "prompt" => $prompt,
                "stream" => false,
            ]);
            if ($res->ok()) {
                $json = $res->json();
                // different Ollama setups return different keys
                if (isset($json["response"])) {
                    return $json["response"];
                }
                if (isset($json["text"])) {
                    return $json["text"];
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return "";
    }
}
