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
     * @param array{
     *   conversation_id?: int,
     *   query?: string,
     *   messages?: array<array{role:string,content:string}>
     * } $payload
     */
    public function answer(array $payload): array
    {
        $conversationId = $payload["conversation_id"] ?? null;
        $messages = $payload["messages"] ?? null;
        $query = $payload["query"] ?? null;

        // ถ้า frontend / code เก่า ส่งมาแค่ query → สร้าง messages ให้เอง
        if ($messages === null) {
            if ($query === null) {
                throw new \InvalidArgumentException(
                    'Either "messages" or "query" is required.',
                );
            }

            $messages = [
                [
                    "role" => "user",
                    "content" => $query,
                ],
            ];
        }

        // ---- จากตรงนี้ไป ใช้ $messages เป็นหลัก ----
        // ตรงนี้เอาไปต่อกับ logic เดิมของคุณ เช่น RAG + LLM
        // ตัวอย่าง pseudo-code:

        /*
             $ragResult = $this->kbService->searchRelevantDocs($messages, $conversationId);

             $llmResponse = $this->llmClient->chat([
                 'model' => '...',
                 'messages' => array_merge(
                     [
                         [
                             'role' => 'system',
                             'content' => 'You are ...',
                         ],
                     ],
                     $messages
                 ),
                 'kb_context' => $ragResult,
             ]);
             */

        // สมมติสุดท้ายได้ text กับ kb_hits กลับมา:
        $text = $this->callYourLlmAndGetText($messages, $conversationId);
        $kbHits = []; // หรือจาก RAG จริงของคุณ

        return [
            "text" => $text,
            "kb_hits" => $kbHits,
        ];
    }

    /**
     * ตรงนี้แทนที่ด้วยการเรียก LLM จริงของคุณ (OpenAI / Ollama / อะไรก็ได้)
     */
    protected function callYourLlmAndGetText(
        array $messages,
        ?int $conversationId,
    ): string {
        $response = Http::post("http://ollama:11434/api/chat", [
            "model" => "llama3:8b",
            "messages" => $messages,
        ]);

        return $response->json("message.content");
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
