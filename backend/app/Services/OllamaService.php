<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class OllamaService
{
    protected string $host;
    protected string $model;

    public function __construct()
    {
        $this->host = rtrim(env("OLLAMA_URL", "http://ollama:11434"), "/");
        $this->model = env("OLLAMA_MODEL", "llama3.1:8b");
    }

    /* ---------------------------------------------------------
     * Regular non-stream generation
     * --------------------------------------------------------- */
    public function generate(string $prompt): string
    {
        $res = Http::timeout(60)->post($this->host . "/api/generate", [
            "model" => $this->model,
            "prompt" => $prompt,
            "stream" => false,
        ]);

        if ($res->failed()) {
            \Log::error("Ollama generate() failed", [
                "status" => $res->status(),
                "body" => $res->body(),
            ]);
            return "";
        }

        return $res->json("response") ?? "";
    }

    /* ---------------------------------------------------------
     * STREAMING GENERATION
     *   $callback receives each partial text chunk
     * --------------------------------------------------------- */
    public function streamGenerate(string $prompt, callable $callback): void
    {
        $url = $this->host . "/api/generate";

        // Use Streamed HTTP client
        $response = Http::withHeaders([
            "Accept" => "application/json",
        ])
            ->timeout(0) // allow streaming
            ->withOptions(["stream" => true])
            ->post($url, [
                "model" => $this->model,
                "prompt" => $prompt,
                "stream" => true,
            ]);

        if ($response->failed()) {
            \Log::error("Ollama streamGenerate() failed", [
                "status" => $response->status(),
                "body" => $response->body(),
            ]);
            return;
        }

        /** @var \GuzzleHttp\Psr7\Stream $body */
        $body = $response->toPsrResponse()->getBody();

        while (!$body->eof()) {
            $line = trim($body->read(4096));
            if (!$line) {
                usleep(10_000);
                continue;
            }

            // handle NDJSON streaming lines
            $parts = explode("\n", $line);
            foreach ($parts as $jsonLine) {
                $jsonLine = trim($jsonLine);
                if (!$jsonLine) {
                    continue;
                }

                $data = json_decode($jsonLine, true);
                if (!is_array($data)) {
                    continue;
                }

                if (isset($data["response"]) && !$data["done"]) {
                    $callback($data["response"]);
                }

                if (($data["done"] ?? false) === true) {
                    return;
                }
            }
        }
    }

    /* ---------------------------------------------------------
     * Embedding request for RAG
     * --------------------------------------------------------- */
    public function getEmbedding(string $text): array
    {
        $res = Http::timeout(60)->post($this->host . "/api/embeddings", [
            "model" => $this->model,
            "prompt" => $text,
        ]);

        if ($res->failed()) {
            \Log::error("Ollama embedding failed", [
                "status" => $res->status(),
                "body" => $res->body(),
            ]);
            return [];
        }

        return $res->json("embedding") ?? [];
    }
}
