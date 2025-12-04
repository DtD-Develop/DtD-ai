<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class QueryService
{
    public function __construct(
        protected ?string $ingestEndpoint = null,
        protected ?string $qdrantUrl = null,
        protected ?string $ollamaUrl = null,
        protected ?string $ollamaModel = null,
    ) {
        $this->ingestEndpoint =
            $this->ingestEndpoint ?:
            config("services.ingest.endpoint", env("INGEST_ENDPOINT"));
        $this->qdrantUrl =
            $this->qdrantUrl ?: env("QDRANT_URL", "http://qdrant:6333");
        $this->ollamaUrl =
            $this->ollamaUrl ?: env("OLLAMA_URL", "http://ollama:11434");
        $this->ollamaModel =
            $this->ollamaModel ?: env("OLLAMA_MODEL", "llama3.1:8b");
    }

    /**
     * ตอบคำถามจาก KB + LLM
     *
     * @param array $payload [
     *   'query' => string,
     *   'conversation_id' => int|null,
     *   'top_k_kb' => int,
     *   'min_kb_score' => float,
     * ]
     *
     * @return array [
     *   'text' => string,
     *   'kb_hits' => array,
     * ]
     */
    public function answer(array $payload): array
    {
        $query = $payload["query"] ?? "";
        $topK = $payload["top_k_kb"] ?? 5;
        $minScore = $payload["min_kb_score"] ?? 0.3;
        $collection = env("QDRANT_COLLECTION", "dtd_kb");

        // 1) เรียก ingest เพื่อ embed query เป็น vector
        $embedRes = Http::post(
            rtrim($this->ingestEndpoint, "/") . "/embed-text",
            [
                "text" => $query,
            ],
        );

        if (!$embedRes->ok()) {
            // fallback ไป LLM เปล่า ๆ
            return [
                "text" => $this->callLlmPlain($query),
                "kb_hits" => [],
            ];
        }

        $vector = $embedRes->json("vector") ?? [];

        // 2) ค้นใน Qdrant
        $searchRes = Http::post(
            rtrim($this->qdrantUrl, "/") .
                "/collections/" .
                $collection .
                "/points/search",
            [
                "vector" => $vector,
                "limit" => $topK,
                "with_payload" => true,
            ],
        );

        $hits = [];
        if ($searchRes->ok()) {
            $hits = collect($searchRes->json("result") ?? [])
                ->filter(fn($hit) => ($hit["score"] ?? 0) >= $minScore)
                ->values()
                ->all();
        }

        // 3) สร้าง prompt ให้ LLM
        if (empty($hits)) {
            // ไม่มี KB context → ตอบแบบทั่วไป
            return [
                "text" => $this->callLlmPlain($query),
                "kb_hits" => [],
            ];
        }

        $contextTexts = collect($hits)
            ->map(function ($hit) {
                $payload = $hit["payload"] ?? [];
                return $payload["text"] ?? "";
            })
            ->filter()
            ->values()
            ->all();

        $prompt = $this->buildKbPrompt($query, $contextTexts);

        $answer = $this->callLlmWithPrompt($prompt);

        return [
            "text" => $answer,
            "kb_hits" => $hits,
        ];
    }

    protected function buildKbPrompt(string $query, array $contextTexts): string
    {
        $ctx = implode("\n\n---\n\n", $contextTexts);

        return <<<PROMPT
        คุณคือ AI assistant ที่ตอบคำถามจาก Knowledge Base ที่ให้ไว้ด้านล่างเท่านั้น

        [CONTEXT START]
        {$ctx}
        [CONTEXT END]

        กฎ:
        - ถ้าคำตอบไม่มีใน context ให้ตอบว่า "จากข้อมูลที่มีอยู่ ฉันไม่พบคำตอบในเอกสาร"
        - ห้ามแต่งเรื่องเอง ห้ามตอบข้อมูลที่ไม่มีใน context
        - อธิบายให้กระชับ ชัดเจน และมีโครงสร้าง อ่านง่าย

        คำถามของผู้ใช้:
        {$query}
        PROMPT;
    }

    protected function callLlmPlain(string $query): string
    {
        $prompt =
            "ตอบคำถามต่อไปนี้ให้ชัดเจน กระชับ และมีโครงสร้าง:\n\n" . $query;

        return $this->callLlmWithPrompt($prompt);
    }

    protected function callLlmWithPrompt(string $prompt): string
    {
        $res = Http::post(rtrim($this->ollamaUrl, "/") . "/api/generate", [
            "model" => $this->ollamaModel,
            "prompt" => $prompt,
            "stream" => false,
        ]);

        if (!$res->ok()) {
            return "ขออภัย ระบบไม่สามารถตอบได้ในขณะนี้";
        }

        // แล้วแต่รูปแบบ response ของ ollama ที่คุณใช้
        $body = $res->json();
        return $body["response"] ??
            ($body["text"] ?? "ขออภัย ระบบไม่สามารถตอบได้ในขณะนี้");
    }
}
