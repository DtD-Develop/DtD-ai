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

        // ฟิลเตอร์ KB
        $sourceFilter = $request->input("source"); // string
        $sources = $request->input("sources", []); // array<string>
        $tags = $request->input("tags", []); // array<string>
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
            \Log::warning("embed failed: " . $e->getMessage());
        }

        // 2) KB search (semantic + filters + threshold)
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

                $must = [];
                if ($sourceFilter) {
                    $must[] = [
                        "key" => "source",
                        "match" => ["value" => $sourceFilter],
                    ];
                }
                if (is_array($sources) && count($sources) > 0) {
                    $should = [];
                    foreach ($sources as $s) {
                        $should[] = [
                            "key" => "source",
                            "match" => ["value" => $s],
                        ];
                    }
                    $must[] = ["should" => $should];
                }
                if (is_array($tags) && count($tags) > 0) {
                    foreach ($tags as $t) {
                        $must[] = ["key" => "tags", "match" => ["value" => $t]];
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
                            "[k{$i}] source=" .
                            ($payload["source"] ?? "unknown") .
                            " score=" .
                            round($score, 3);
                        $kbHits[] = [
                            "k" => "k{$i}",
                            "source" => $payload["source"] ?? "unknown",
                            "doc_id" => $payload["doc_id"] ?? null,
                            "score" => $score,
                            "chunk_idx" => $payload["chunk_idx"] ?? null,
                        ];
                        $i++;
                    }
                }
            } catch (\Exception $e) {
                \Log::warning("kb search failed: " . $e->getMessage());
            }
        }

        // 2.1 Fallback: ถ้าไม่เจอ KB แต่มี source → scroll ตามไฟล์
        if (count($kbContexts) === 0 && $sourceFilter) {
            try {
                $resp = $qdrantClient->post(
                    "/collections/{$kbCollection}/points/scroll",
                    [
                        "json" => [
                            "limit" => $kbLimit,
                            "with_payload" => true,
                            "with_vectors" => false,
                            "filter" => [
                                "must" => [
                                    [
                                        "key" => "source",
                                        "match" => ["value" => $sourceFilter],
                                    ],
                                ],
                            ],
                        ],
                    ],
                );
                $js = json_decode((string) $resp->getBody(), true);
                $i = 1;
                foreach ($js["result"]["points"] ?? [] as $p) {
                    $pl = $p["payload"] ?? [];
                    if (isset($pl["text"])) {
                        $kbContexts[] = $pl["text"];
                        $kbCitations[] =
                            "[k{$i}] source=" .
                            ($pl["source"] ?? "unknown") .
                            " score=scroll";
                        $kbHits[] = [
                            "k" => "k{$i}",
                            "source" => $pl["source"] ?? "unknown",
                            "doc_id" => $pl["doc_id"] ?? null,
                            "score" => null,
                            "chunk_idx" => $pl["chunk_idx"] ?? null,
                        ];
                        $i++;
                        if (count($kbContexts) >= $kbLimit) {
                            break;
                        }
                    }
                }
            } catch (\Exception $e) {
                \Log::warning("kb scroll fallback failed: " . $e->getMessage());
            }
        }

        // 3) Chat memory (semantic + recent)
        $chatSnippets = [];
        if ($queryVector) {
            try {
                $chatRes = $ingestClient->post("/chat/search", [
                    "json" => [
                        "conversation_id" => $conversationId,
                        "query" => $q,
                        "limit" => 6,
                    ],
                ]);
                $chatJson = json_decode((string) $chatRes->getBody(), true);
                foreach ($chatJson["results"] ?? [] as $hit) {
                    $p = $hit["payload"] ?? [];
                    if (isset($p["role"], $p["text"])) {
                        $chatSnippets[] =
                            strtoupper($p["role"]) . ": " . $p["text"];
                    }
                }
            } catch (\Exception $e) {
                \Log::warning("chat/search failed: " . $e->getMessage());
            }
        }
        try {
            $recentRes = $ingestClient->post("/chat/recent", [
                "json" => ["conversation_id" => $conversationId, "limit" => 8],
            ]);
            $recentJson = json_decode((string) $recentRes->getBody(), true);
            foreach ($recentJson["results"] ?? [] as $item) {
                $p = $item["payload"] ?? [];
                if (isset($p["role"], $p["text"])) {
                    $line = strtoupper($p["role"]) . ": " . $p["text"];
                    if (!in_array($line, $chatSnippets)) {
                        $chatSnippets[] = $line;
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::warning("chat/recent failed: " . $e->getMessage());
        }

        // 4) Prompt เข้มงวด
        $kbContextText = implode("\n---\n", $kbContexts);
        $kbCitationsText = implode("\n", $kbCitations);
        $chatContextText = implode("\n", $chatSnippets);

        $prompt =
            "You are a precise assistant. STRICTLY follow the rules:\n" .
            "1) Use ONLY information from KB Context to answer. If not found, say 'ไม่พบข้อมูลในเอกสาร'.\n" .
            "2) If Chat Memory conflicts with KB Context, IGNORE Chat Memory and prefer KB Context.\n" .
            "3) Include citations like [k1], [k2] where applicable.\n" .
            "4) Answer in the same language as the question. Keep it concise.\n\n" .
            "KB Context:\n{$kbContextText}\n\n" .
            "KB Citations:\n{$kbCitationsText}\n\n" .
            "Chat Memory (intent only, do not use as facts if conflict):\n{$chatContextText}\n\n" .
            "Question:\n{$q}\n";

        // 5) LLM
        try {
            $res2 = $ollamaClient->post("/api/generate", [
                "json" => [
                    "model" => $ollamaModel,
                    "prompt" => $prompt,
                    "stream" => false,
                    "keep_alive" => "15m",
                    "options" => [
                        "num_predict" => 256,
                        "temperature" => 0.2,
                        "top_p" => 0.95,
                        "num_ctx" => 6144,
                    ],
                ],
            ]);
            $ollamaRes = json_decode((string) $res2->getBody(), true);
            $answer = $ollamaRes["response"] ?? ($ollamaRes["output"] ?? null);
            if ($answer === null) {
                $answer = json_encode($ollamaRes);
            }
        } catch (\Exception $e) {
            $answer = "Ollama call failed: " . $e->getMessage();
        }

        // 6) Save chat
        try {
            $ingestClient->post("/chat/upsert", [
                "json" => [
                    "conversation_id" => $conversationId,
                    "role" => "user",
                    "text" => $q,
                ],
            ]);
        } catch (\Exception $e) {
        }
        try {
            $ingestClient->post("/chat/upsert", [
                "json" => [
                    "conversation_id" => $conversationId,
                    "role" => "assistant",
                    "text" => $answer,
                ],
            ]);
        } catch (\Exception $e) {
        }

        return response()->json([
            "answer" => $answer,
            "model_used" => $ollamaModel,
            "conversation_id" => $conversationId,
            "kb_used" => count($kbContexts),
            "kb_hits" => $kbHits,
            "chat_memory_used" => count($chatSnippets),
        ]);
    }
}
