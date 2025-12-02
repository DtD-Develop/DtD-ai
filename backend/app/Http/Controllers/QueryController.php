<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use GuzzleHttp\Client;

class QueryController
{
    public function query(Request $request)
    {
        $q = $request->input("query", "");
        $conversationId =
            $request->input("conversation_id") ?: bin2hex(random_bytes(16));

        $mode = $request->input("mode", "test");
        $userId = $request->input("user_id");

        $sourceFilter = $request->input("source");
        $sources = $request->input("sources", []);
        $tags = $request->input("tags", []);
        $kbLimit = intval($request->input("top_k_kb", 8));
        $minKbScore = floatval($request->input("min_kb_score", 0.2));

        $qdrantUrl = env("QDRANT_URL", "http://qdrant:6333");
        $ollamaUrl = env("OLLAMA_URL", "http://ollama:11434");
        $ollamaModel = env("OLLAMA_MODEL", "llama3.1:8b");
        $ingestUrl = env("INGEST_URL", "http://ingest_service:8001");
        $kbCollection = env("QDRANT_COLLECTION", "company_kb");

        $qdrantClient = new Client([
            "base_uri" => $qdrantUrl,
            "timeout" => 10.0,
        ]);
        $ingestClient = new Client([
            "base_uri" => $ingestUrl,
            "timeout" => 30.0,
        ]);
        $ollamaClient = new Client([
            "base_uri" => $ollamaUrl,
            "timeout" => 600.0,
            "read_timeout" => 600.0,
            "connect_timeout" => 10.0,
        ]);

        // 1) Embed query
        $queryVector = null;
        try {
            $embedRes = $ingestClient->post("/embed", [
                "json" => ["text" => $q],
            ]);
            $embedJson = json_decode((string) $embedRes->getBody(), true);
            $queryVector = $embedJson["vector"] ?? null;
        } catch (\Exception $e) {
            Log::warning("embed failed: " . $e->getMessage());
        }

        // 2) KB semantic search
        $kbContexts = [];
        $kbCitations = [];
        $kbHits = [];

        if ($queryVector) {
            try {
                $searchBody = [
                    "vector" => $queryVector,
                    "limit" => $kbLimit,
                    "with_payload" => true,
                ];

                // Filters
                $must = [];
                if ($sourceFilter) {
                    $must[] = [
                        "key" => "source",
                        "match" => ["value" => $sourceFilter],
                    ];
                }
                if (is_array($sources) && count($sources) > 0) {
                    foreach ($sources as $s) {
                        $must[] = [
                            "key" => "source",
                            "match" => ["value" => $s],
                        ];
                    }
                }

                if (count($must) > 0) {
                    $searchBody["filter"] = ["must" => $must];
                }

                $resp = $qdrantClient->post(
                    "/collections/{$kbCollection}/points/search",
                    ["json" => $searchBody],
                );
                $qdrantRes = json_decode((string) $resp->getBody(), true);

                $i = 1;
                foreach ($qdrantRes["result"] ?? [] as $r) {
                    $score = $r["score"] ?? 0.0;
                    $payload = $r["payload"] ?? [];

                    if ($score >= $minKbScore && isset($payload["text"])) {
                        $kbContexts[] = $payload["text"];
                        $kbCitations[] =
                            "[k{$i}] " .
                            ($payload["source"] ?? "unknown") .
                            " score=" .
                            round($score, 3);
                        $kbHits[] = [
                            "k" => "k{$i}",
                            "source" => $payload["source"] ?? "unknown",
                            "doc_id" => $payload["doc_id"] ?? null,
                            "score" => $score,
                        ];
                        $i++;
                    }
                }
            } catch (\Exception $e) {
                Log::warning("kb search failed: " . $e->getMessage());
            }
        }

        // 2.1 Extract all tags for safety check
        $qdrantTags = [];
        try {
            $respTags = $qdrantClient->post(
                "/collections/{$kbCollection}/points/scroll",
                [
                    "json" => [
                        "limit" => 500,
                        "with_payload" => true,
                        "with_vectors" => false,
                    ],
                ],
            );
            $tagData = json_decode((string) $respTags->getBody(), true);
            foreach ($tagData["result"]["points"] ?? [] as $p) {
                $payload = $p["payload"] ?? [];
                if (isset($payload["tags"]) && is_array($payload["tags"])) {
                    foreach ($payload["tags"] as $t) {
                        $qdrantTags[strtolower($t)] = true;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning("fetch tags failed: " . $e->getMessage());
        }

        $isTagRelated = false;
        foreach (array_keys($qdrantTags) as $tag) {
            if (stripos(strtolower($q), $tag) !== false) {
                $isTagRelated = true;
                break;
            }
        }

        // 3) Final fallback if KB not found
        if (empty($kbContexts)) {
            if ($isTagRelated) {
                return response()->json([
                    "answer" => "ไม่พบข้อมูลในเอกสาร",
                    "conversation_id" => $conversationId,
                    "kb_used" => 0,
                ]);
            }

            // Chatbot mode
            $fallbackRes = $ollamaClient->post("/api/generate", [
                "json" => [
                    "model" => $ollamaModel,
                    "prompt" =>
                        "ตอบคำถามให้เป็นธรรมชาติและใช้ภาษาตรงกับคำถาม:\n\n" .
                        "Question:\n{$q}\n",
                    "stream" => false,
                ],
            ]);
            $js = json_decode((string) $fallbackRes->getBody(), true);
            return response()->json([
                "answer" => $js["response"] ?? "ฉันสามารถช่วยอะไรได้บ้าง?",
                "conversation_id" => $conversationId,
            ]);
        }

        // 4) Build KB Prompt
        $prompt =
            "ตอบตามข้อมูลจาก KB เท่านั้น ถ้า KB ไม่มีข้อมูลให้ตอบว่าไม่พบข้อมูลในเอกสาร\n\n" .
            "KB:\n" .
            implode("\n---\n", $kbContexts) .
            "\n\n" .
            "Question:\n{$q}\n";

        // 5) Ask LLM
        try {
            $res = $ollamaClient->post("/api/generate", [
                "json" => [
                    "model" => $ollamaModel,
                    "prompt" => $prompt,
                    "stream" => false,
                    "options" => [
                        "temperature" => 0.2,
                        "num_predict" => 256,
                    ],
                ],
            ]);
            $ollamaRes = json_decode((string) $res->getBody(), true);
            $answer = $ollamaRes["response"] ?? "ไม่พบข้อมูลในเอกสาร";
        } catch (\Exception $e) {
            $answer = "Ollama call failed: " . $e->getMessage();
        }

        return response()->json([
            "answer" => $answer,
            "conversation_id" => $conversationId,
            "kb_used" => count($kbContexts),
            "kb_hits" => $kbHits,
        ]);
    }
}
