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
     * NON-STREAMING GENERATION
     * --------------------------------------------------------- */
    public function generate(string $prompt): string
    {
        $res = Http::timeout(60)->post("{$this->host}/api/generate", [
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
     * STREAM GENERATION (NDJSON)
     * $callback receives partial tokens: fn(string $chunk) {}
     * --------------------------------------------------------- */
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
            \Log::error("Ollama streamGenerate() HTTP failed", [
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

            // NDJSON จะแบ่งด้วย newline
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines); // เก็บ remainder เอาไว้ยังไม่สมบูรณ์

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === "") {
                    continue;
                }

                $json = json_decode($line, true);

                if (!is_array($json)) {
                    \Log::warning("Invalid JSON in stream chunk: " . $line);
                    continue;
                }

                // Ollama ส่ง {"response":"...", "done":false}
                if (
                    isset($json["response"]) &&
                    ($json["done"] ?? false) === false
                ) {
                    $callback($json["response"]);
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
    public function getEmbedding(string $text): array
    {
        $res = Http::timeout(60)->post("{$this->host}/api/embeddings", [
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
