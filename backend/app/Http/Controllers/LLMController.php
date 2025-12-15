<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\EmbeddingService;
use App\Services\QdrantService;
use GuzzleHttp\Client;

class LLMController extends Controller
{
    protected $qdrant;
    protected $embedSvc;
    protected $http;

    public function __construct(
        EmbeddingService $embedSvc,
        QdrantService $qdrant,
    ) {
        $this->embedSvc = $embedSvc;
        $this->qdrant = $qdrant;
        $this->http = new Client(["timeout" => 30]);
    }

    /**
     * POST /api/llm/answer
     * body: { question, kb_file_id(optional), mode: test|train }
     * This will: embed question, retrieve top context, build prompt, call LLM, return answer + used contexts
     */
    public function answer(Request $req)
    {
        $req->validate([
            "question" => "required|string",
            "kb_file_id" => "nullable|integer",
            "mode" => "nullable|string",
        ]);
        $q = $req->input("question");
        $kbFileId = $req->input("kb_file_id");

        $vec = $this->embedSvc->getEmbedding($q);
        $filter = $kbFileId
            ? ["key" => "kb_file_id", "match" => ["value" => $kbFileId]]
            : null;
        $results = $this->qdrant->search(
            $vec,
            8,
            0.12,
            $filter ? ["should" => [$filter]] : null,
            null,
        );

        // prepare context text (concatenate top3 with source markers)
        $top = array_slice($results, 0, 5);
        $contextText = "";
        foreach ($top as $i => $it) {
            $p = $it["payload"] ?? [];
            $title = $p["title"] ?? ($p["source"] ?? "source");
            $chunkText = $p["text"] ?? "";
            $contextText .= "\n=== SOURCE: {$title} (chunk {$p["chunk_index"]}) ===\n";
            $contextText .= substr($chunkText, 0, 1500) . "\n"; // limit per chunk
        }

        // build prompt (RAG template)
        $system =
            "You are an internal assistant for the company. Use ONLY the provided knowledge below. If the answer is not in the knowledge, say 'ไม่พบข้อมูลในระบบ' (Thai) or 'No information found' (English).";
        $prompt =
            $system .
            "\n\nKnowledge:\n" .
            $contextText .
            "\n\nUser question:\n" .
            $q .
            "\n\nAnswer succinctly in the same language as the question.";

        // call LLM (OpenAI example using chat completions)
        $openaiKey = env("OPENAI_API_KEY");
        if (!$openaiKey) {
            return response()->json(
                ["error" => "No LLM provider configured"],
                500,
            );
        }

        $resp = $this->http->post(
            "https://api.openai.com/v1/chat/completions",
            [
                "headers" => [
                    "Authorization" => "Bearer {$openaiKey}",
                    "Content-Type" => "application/json",
                ],
                "json" => [
                    "model" => env("OPENAI_MODEL", "gpt-4o-mini"), // change as needed
                    "messages" => [
                        ["role" => "system", "content" => $system],
                        ["role" => "user", "content" => $prompt],
                    ],
                    "temperature" => 0.0,
                    "max_tokens" => 800,
                ],
            ],
        );

        $respData = json_decode((string) $resp->getBody(), true);
        $answer = $respData["choices"][0]["message"]["content"] ?? "";

        return response()->json([
            "answer" => $answer,
            "contexts" => $top,
            "raw_llm" => $respData,
        ]);
    }
}
