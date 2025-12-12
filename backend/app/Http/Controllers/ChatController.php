<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\QueryService;
use App\Services\OllamaService;

class ChatController extends Controller
{
    protected QueryService $query;
    protected OllamaService $llm;

    public function __construct(QueryService $query, OllamaService $llm)
    {
        $this->query = $query;
        $this->llm = $llm;
    }

    /* ---------------------------------------------------------
     *  Conversation CRUD
     * --------------------------------------------------------- */

    public function index()
    {
        return Conversation::withCount("messages")
            ->orderBy("created_at", "desc")
            ->get();
    }

    public function storeConversation(Request $req)
    {
        $req->validate([
            "title" => "nullable|string",
        ]);

        $conversation = Conversation::create([
            "title" => $req->title ?? "New Conversation",
        ]);

        return response()->json($conversation, 201);
    }

    public function showConversation(Conversation $conversation)
    {
        $conversation->load([
            "messages" => function ($q) {
                $q->orderBy("created_at");
            },
        ]);

        return $conversation;
    }

    public function updateConversation(Request $req, Conversation $conversation)
    {
        $req->validate([
            "title" => "required|string",
        ]);

        $conversation->update([
            "title" => $req->title,
        ]);

        return $conversation;
    }

    public function destroyConversation(Conversation $conversation)
    {
        $conversation->messages()->delete();
        $conversation->delete();

        return response()->json(["status" => "deleted"]);
    }

    /* ---------------------------------------------------------
     *  MAIN CHAT ENDPOINT (RAG)
     *  POST /chat/message
     * --------------------------------------------------------- */

    public function message(Request $req)
    {
        \Log::info("Hit ChatController@message", [
            "path" => $req->path(),
        ]);

        $req->validate([
            "conversation_id" => "required|integer|exists:conversations,id",
            "message" => "required|string",
            "mode" => "nullable|in:test,train",
        ]);

        $question = $req->message;

        $contexts = $this->query->searchKB($question, 4);
        $prompt = $this->buildRagPrompt($question, $contexts);
        $answer = $this->llm->generate($prompt);

        $msg = Message::create([
            "conversation_id" => $req->conversation_id,
            "question" => $question,
            "answer" => $answer,
            "mode" => $req->mode ?? "test",
            "rag_context" => json_encode($contexts),
        ]);

        $resp = response()->json([
            "message_id" => $msg->id,
            "answer" => $answer,
            "kb_hits" => $contexts,
            "rag_prompt" => $prompt,
        ]);

        $resp->headers->set("X-Debug-Controller", "ChatController-message");

        return $resp;
    }
    /* ---------------------------------------------------------
     *  Manual Rating Endpoint
     *  POST /chat/messages/{message}/rate
     * --------------------------------------------------------- */

    public function rate(Request $req, Message $message)
    {
        $req->validate([
            "score" => "required|in:good,bad",
        ]);

        $message->update([
            "manual_score" => $req->score,
        ]);

        return [
            "status" => "ok",
            "message_id" => $message->id,
            "manual_score" => $message->manual_score,
        ];
    }

    /* ---------------------------------------------------------
     *  RAG PROMPT BUILDER
     * --------------------------------------------------------- */

    protected function buildRagPrompt(string $query, array $contexts): string
    {
        $header =
            "You are an AI assistant that answers ONLY using the information provided in the Knowledge Base below.\n\n";

        if (count($contexts) === 0) {
            $header .= "[NO CONTEXT AVAILABLE]\n\n";
        } else {
            $header .= "[KNOWLEDGE BASE CONTEXT]\n";
            foreach ($contexts as $i => $c) {
                $text = trim($c["payload"]["text"] ?? "");
                $header .= "[" . ($i + 1) . "] " . $text . "\n\n";
            }
            $header .= "[END OF CONTEXT]\n\n";
        }

        $header .= <<<RULES
        Rules:
        - If the answer is not in the context, reply: "I don't have this information in the knowledge base."
        - Do NOT invent information that is not in the provided KB context.
        - Keep answers concise, factual, and well-structured.
        - If relevant, cite which snippet(s) your answer is based on.
        RULES;

        return $header . "\n\nUser Question:\n\"{$query}\"\n\nFinal Answer:\n";
    }
}
