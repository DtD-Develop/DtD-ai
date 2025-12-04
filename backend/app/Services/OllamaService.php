<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

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
     * ใช้ generate ข้อความ (ไม่ stream)
     */
    public function generate(string $prompt): string
    {
        $res = Http::post($this->baseUrl . "/api/generate", [
            "model" => $this->model,
            "prompt" => $prompt,
            "stream" => false,
        ]);

        if (!$res->ok()) {
            return "";
        }

        $data = $res->json();

        // แล้วแต่ format ที่คุณใช้กับ Ollama
        return $data["response"] ?? ($data["text"] ?? "");
    }
}
