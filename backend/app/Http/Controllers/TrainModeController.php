<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AiScoringService;
use App\Services\KnowledgeStoreService;
use App\Services\QueryService;

class TrainModeController extends Controller
{
    public function chat(
        Request $req,
        QueryService $queryService,
        AiScoringService $scoring,
        KnowledgeStoreService $knowledgeStore,
    ) {
        $req->validate([
            "conversation_id" => "required|integer",
            "message" => "required|string",
            "manual_score" => "nullable|in:good,bad",
        ]);

        // 1) Retrieve context from KB
        $context = $queryService->search($req->message);

        // 2) LLM answer
        $answer = $queryService->generateAnswer($req->message, $context);

        // 3) AI scoring
        $aiScore = $scoring->evaluate($req->message, $answer);

        // 4) Save message
        $message = Message::create([
            "conversation_id" => $req->conversation_id,
            "question" => $req->message,
            "answer" => $answer,
            "mode" => "train",
            "ai_score" => $aiScore,
            "manual_score" => $req->manual_score,
            "rag_context" => json_encode($context),
        ]);

        // 5) Decide if should store into KB
        $shouldStore = false;

        if ($req->manual_score === "good") {
            $shouldStore = true;
        } elseif (is_null($req->manual_score) && $aiScore >= 4) {
            $shouldStore = true;
        }

        if ($shouldStore) {
            $knowledgeStore->storeText($answer, tags: ["training", "auto"]);
        }

        return response()->json([
            "answer" => $answer,
            "ai_score" => $aiScore,
            "manual_score" => $req->manual_score,
            "stored_in_kb" => $shouldStore,
        ]);
    }

    // Users can update score afterward
    public function score(Request $req, KnowledgeStoreService $store)
    {
        $req->validate([
            "message_id" => "required|integer",
            "manual_score" => "required|in:good,bad",
        ]);

        $msg = Message::findOrFail($req->message_id);
        $msg->manual_score = $req->manual_score;
        $msg->save();

        if ($req->manual_score === "good") {
            // store answer into KB
            $store->storeText($msg->answer, tags: ["training", "manual"]);
        }

        return [
            "status" => "updated",
            "manual_score" => $req->manual_score,
        ];
    }
}
