<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\EmbeddingService;
use App\Services\QdrantService;

class RagController extends Controller
{
    protected $embedSvc;
    protected $qdrant;

    public function __construct(
        EmbeddingService $embedSvc,
        QdrantService $qdrant,
    ) {
        $this->embedSvc = $embedSvc;
        $this->qdrant = $qdrant;
    }

    /**
     * POST /api/rag/query
     * body: { "question": "...", "kb_file_id": optional, "mode": "test"|"train", "keyword": optional }
     */
    public function query(Request $req)
    {
        $req->validate([
            "question" => "required|string",
            "kb_file_id" => "nullable|integer",
            "mode" => "nullable|string",
            "keyword" => "nullable|string",
        ]);

        $question = $req->input("question");
        $kbFileId = $req->input("kb_file_id");
        $keyword = $req->input("keyword");

        // 1) get embedding for question
        $vec = $this->embedSvc->getEmbedding($question);

        // 2) build filter if kb_file_id provided
        $filter = null;
        if ($kbFileId) {
            $filter = [
                "key" => "kb_file_id",
                "match" => ["value" => $kbFileId],
            ];
        }

        // 3) search Qdrant (hybrid vector + payload filter + optional keyword)
        $topK = 8;
        $scoreThreshold = 0.12; // tune this later
        $results = $this->qdrant->search(
            $vec,
            $topK,
            $scoreThreshold,
            $filter ? ["should" => [$filter]] : null,
            $keyword,
        );

        // 4) build RAG prompt: gather topN context
        $contextItems = array_slice($results, 0, 5);
        $contexts = array_map(function ($it) {
            $p = $it["payload"] ?? [];
            $text = $p["text"] ?? "";
            $source = $p["source"] ?? null;
            $title = $p["title"] ?? null;
            return [
                "text" => $text,
                "source" => $source,
                "title" => $title,
                "kb_file_id" => $p["kb_file_id"] ?? null,
                "chunk_index" => $p["chunk_index"] ?? null,
            ];
        }, $contextItems);

        // 5) craft system prompt and user prompt for your LLM service (here we return context + will ask frontend to call LLM)
        // Option A: backend calls LLM (if you have OpenAI / local LLM)
        // Option B: return context to frontend and let frontend send to LLM service

        // For safety & speed, we'll return assembled payload: context chunks + question
        return response()->json([
            "question" => $question,
            "contexts" => $contexts,
            "raw_results" => $results,
        ]);
    }
}
