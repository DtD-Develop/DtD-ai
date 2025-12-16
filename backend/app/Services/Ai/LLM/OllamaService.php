<?php

namespace App\Services\Ai\LLM;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OllamaService
{
    protected string $host;
    protected string $model;

    public function __construct()
    {
        $this->host = rtrim(
            (string) env("OLLAMA_URL", "http://ollama:11434"),
            "/",
        );
        $this->model = (string) env("OLLAMA_MODEL", "llama3.1:8b");
    }

    /* ---------------------------------------------------------
     * NON-STREAMING GENERATION
     * --------------------------------------------------------- */

    /**
     * Call Ollama /api/generate with stream=false and return full response text.
     */
    public function generate(string $prompt): string
    {
        $response = Http::timeout(60)->post("{$this->host}/api/generate", [
            "model" => $this->model,
            "prompt" => $prompt,
            "stream" => false,
        ]);

        if ($response->failed()) {
            Log::error("Ollama generate() failed", [
                "status" => $response->status(),
                "body" => $response->body(),
            ]);

            return "";
        }

        return $response->json("response") ?? "";
    }

    /* ---------------------------------------------------------
     * STREAM GENERATION (NDJSON)
     * $callback receives partial tokens: fn(string $chunk): void
     * --------------------------------------------------------- */

    /**
     * Stream tokens from Ollama /api/generate (NDJSON) and invoke callback with each chunk.
     *
     * @param  string   $prompt
     * @param  callable $callback  fn(string $chunk): void
     */
    public function streamGenerate(string $prompt, callable $callback): void
    {
        $response = Http::withHeaders(["Accept" => "application/json"])
            ->timeout(0)
            ->withOptions(["stream" => true])
            ->post("{$this->host}/api/generate", [
                "model" => $this->model,
                "prompt" => $prompt,
                "stream" => true,
            ]);

        if ($response->failed()) {
            Log::error("Ollama streamGenerate() HTTP failed", [
                "status" => $response->status(),
                "body" => $response->body(),
            ]);

            return;
        }

        $stream = $response->toPsrResponse()->getBody();
        $buffer = "";

        while (!$stream->eof()) {
            $chunk = $stream->read(1024);

            if ($chunk === "" || $chunk === false) {
                usleep(10_000);
                continue;
            }

            $buffer .= $chunk;

            // NDJSON: each line is a JSON object
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines); // keep remainder

            foreach ($lines as $line) {
                $line = trim($line);

                if ($line === "") {
                    continue;
                }

                $json = json_decode($line, true);

                if (!is_array($json)) {
                    Log::warning("Invalid JSON in Ollama stream chunk", [
                        "chunk" => $line,
                    ]);
                    continue;
                }

                // Ollama sends {"response":"...", "done":false} ... {"done":true}
                if (
                    isset($json["response"]) &&
                    ($json["done"] ?? false) === false
                ) {
                    $callback((string) $json["response"]);
                }

                if (($json["done"] ?? false) === true) {
                    return;
                }
            }
        }
    }

    /* ---------------------------------------------------------
     * EMBEDDINGS
     * --------------------------------------------------------- */

    /**
     * Get embedding vector for a given text from Ollama /api/embeddings.
     *
     * @return array<int,float>
     */
    public function getEmbedding(string $text): array
    {
        $response = Http::timeout(60)->post("{$this->host}/api/embeddings", [
            "model" => $this->model,
            "prompt" => $text,
        ]);

        if ($response->failed()) {
            Log::error("Ollama embedding() failed", [
                "status" => $response->status(),
                "body" => $response->body(),
            ]);

            return [];
        }

        /** @var array<int,float>|null $embedding */
        $embedding = $response->json("embedding");

        return is_array($embedding) ? $embedding : [];
    }
}
