<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QueryController
{
    public function query(Request $request)
    {
        $query = $request->input("query", "");
        if (!$query) {
            return response()->json(["error" => "query is required"], 400);
        }

        $conversationId =
            $request->input("conversation_id") ?: bin2hex(random_bytes(16));

        $kbLimit = intval($request->input("top_k_kb", 6));
        $minScore = floatval($request->input("min_kb_score", 0.2));

        $qdrantUrl = env("QDRANT_HOST", "http://qdrant:6333");
        $ingestUrl = env("INGEST_URL", "http://ingest:8001");
        $ollamaUrl = env("OLLAMA_URL", "http://ollama:11434");
        $ollamaModel = env("OLLAMA_MODEL", "llama3.1:8b");
        $collection = env("QDRANT_COLLECTION", "dtd_kb");

        // 1) Embed Query
        try {
            $embedRes = Http::timeout(30)->post($ingestUrl . "/embed-text", [
                "text" => $query,
            ]);

            $vector = $embedRes->json("vector");
        } catch (\Throwable $e) {
            Log::error("embed error: " . $e->getMessage());
            $vector = null;
        }

        $kbContexts = [];
        $kbHits = [];

        if ($vector) {
            try {
                $resp = Http::timeout(20)->post(
                    "$qdrantUrl/collections/$collection/points/search",
                    [
                        "vector" => $vector,
                        "limit" => $kbLimit,
                        "with_payload" => true,
                    ],
                );

                $results = $resp->json("result") ?? [];
                foreach ($results as $i => $hit) {
                    $score = $hit["score"] ?? 0;
                    $payload = $hit["payload"] ?? [];

                    if ($score < $minScore || empty($payload["text"])) {
                        continue;
                    }

                    $kbContexts[] = $payload["text"];
                    $kbHits[] = [
                        "chunk_index" => $payload["chunk_index"] ?? null,
                        "kb_file_id" => $payload["kb_file_id"] ?? null,
                        "file_name" => $payload["source"] ?? "unknown",
                        "score" => round($score, 3),
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning("search error: " . $e->getMessage());
            }
        }

        // Fallback no KB
        if (empty($kbContexts)) {
            $answer = $this->callLLM($ollamaUrl, $ollamaModel, $query);
            return response()->json([
                "answer" => $answer,
                "conversation_id" => $conversationId,
                "kb_used" => 0,
            ]);
        }

        $ctx = implode("\n---\n", $kbContexts);

        $prompt = "Answer strictly based on the information from the Knowledge Base (KB). If the answer cannot be found in the KB, reply that it is not available.\n\nKB:\n$ctx\n\nQuestion:\n$query";

        $answer = $this->callLLM($ollamaUrl, $ollamaModel, $prompt);

        return response()->json([
            "answer" => $answer,
            "kb_used" => count($kbContexts),
            "kb_hits" => $kbHits,
            "conversation_id" => $conversationId,
        ]);
    }

    private function callLLM($base, $model, $prompt)
    {
        try {
            $res = Http::timeout(300)->post("$base/api/generate", [
                "model" => $model,
                "prompt" => $prompt,
                "stream" => false,
                "options" => [
                    "temperature" => 0.2,
                ],
            ]);
            return $res->json("response") ?? "ไม่พบข้อมูล";
        } catch (\Throwable $e) {
            return "ไม่พบข้อมูล";
        }
    }
}
