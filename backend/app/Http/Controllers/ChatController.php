<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\QueryService;
use App\Services\OllamaService;
use App\Services\AiScoringService;
use App\Services\KnowledgeStoreService;
use App\Jobs\GenerateConversationTitleJob;

class ChatController extends Controller
{
    protected QueryService $query;
    protected OllamaService $llm;
    protected AiScoringService $scorer;
    protected KnowledgeStoreService $kb;

    public function __construct(
        QueryService $query,
        OllamaService $llm,
        AiScoringService $scorer,
        KnowledgeStoreService $kb,
    ) {
        $this->query = $query;
        $this->llm = $llm;
        $this->scorer = $scorer;
        $this->kb = $kb;
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
            "title" => $req->title ?? "",
            "is_title_generated" => false,
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
            "is_title_generated" => true,
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
     *  MAIN CHAT ENDPOINT (TEST / TRAIN)
     * --------------------------------------------------------- */

    public function message(Request $req)
    {
        \Log::info("Hit ChatController@message");

        // conversation_id = nullable (ไม่ส่งครั้งแรก)
        $validator = \Validator::make($req->all(), [
            "conversation_id" => "nullable|integer|exists:conversations,id",
            "message" => "required|string",
            "mode" => "nullable|in:test,train",
        ]);

        if ($validator->fails()) {
            return response()->json(
                [
                    "message" => "Validation failed",
                    "errors" => $validator->errors(),
                ],
                422,
            );
        }

        try {
            $mode = $req->mode ?? "test";
            $question = $req->message;

            /* -----------------------------------------------------
             * AUTO CREATE CONVERSATION IF NOT SENT
             * ----------------------------------------------------- */
            $conversationId = $req->conversation_id;

            if (!$conversationId) {
                $conversation = Conversation::create([
                    "title" => "",
                    "is_title_generated" => false,
                ]);
                $conversationId = $conversation->id;
            }

            /* -----------------------------------------------------
             * SAVE USER MESSAGE FIRST
             * ----------------------------------------------------- */
            $userMsg = Message::create([
                "conversation_id" => $conversationId,
                "role" => "user",
                "content" => $question,
                "is_training" => $mode === "train",
            ]);

            /* -----------------------------------------------------
             * 1) SEARCH KB
             * ----------------------------------------------------- */
            $contexts = $this->query->searchKB($question, 4);

            /* -----------------------------------------------------
             * 2) BUILD PROMPT
             * ----------------------------------------------------- */
            $prompt = $this->buildRagPrompt($question, $contexts);

            /* -----------------------------------------------------
             * 3) LLM ANSWER
             * ----------------------------------------------------- */
            $answer = $this->llm->generate($prompt);
            if (!is_string($answer) || trim($answer) === "") {
                $answer = "I’m sorry, I cannot answer right now.";
            }

            /* -----------------------------------------------------
             * 4) SCORE (TRAIN ONLY)
             * ----------------------------------------------------- */
            $score = null;
            if ($mode === "train") {
                $score = $this->scorer->evaluate($question, $answer);
            }

            /* -----------------------------------------------------
             * 5) SAVE ASSISTANT MESSAGE
             * ----------------------------------------------------- */
            $assistantMsg = Message::create([
                "conversation_id" => $conversationId,
                "role" => "assistant",
                "content" => $answer,
                "score" => $score,
                "is_training" => $mode === "train",
                "meta" => [
                    "question" => $question,
                    "rag_context" => $contexts,
                    "rag_prompt" => $prompt,
                ],
            ]);

            /* -----------------------------------------------------
             * 6) AUTO ADD TO KB (TRAIN MODE + GOOD SCORE)
             * ----------------------------------------------------- */
            if ($mode === "train" && $score >= 4) {
                $this->kb->storeText($answer, ["auto_train"]);
            }

            /* -----------------------------------------------------
             * 7) DISPATCH TITLE GENERATION JOB
             * ----------------------------------------------------- */
            GenerateConversationTitleJob::dispatch($conversationId);

            return response()->json([
                "conversation_id" => $conversationId,
                "message_id" => $assistantMsg->id,
                "answer" => $answer,
                "score" => $score,
                "mode" => $mode,
                "kb_hits" => $contexts,
            ]);
        } catch (\Throwable $e) {
            \Log::error("ChatController@message exception", [
                "message" => $e->getMessage(),
                "trace" => $e->getTraceAsString(),
            ]);

            return response()->json(
                [
                    "message" => "Internal server error",
                ],
                500,
            );
        }
    }

    /* ---------------------------------------------------------
     *  RAG PROMPT BUILDER
     * --------------------------------------------------------- */

    protected function buildRagPrompt(string $query, array $contexts): string
    {
        $header =
            "You are an AI assistant that answers ONLY using the information in the Knowledge Base.\n\n";

        if (count($contexts) === 0) {
            $header .= "[NO CONTEXT]\n\n";
        } else {
            $header .= "[KB CONTEXT]\n";
            foreach ($contexts as $i => $c) {
                $text = trim($c["payload"]["text"] ?? "");
                $header .= "[" . ($i + 1) . "] $text\n\n";
            }
            $header .= "[END CONTEXT]\n\n";
        }

        $header .= <<<RULES
        - If the answer is not in the context, reply:
          "I don't have this information in the knowledge base."
        - Do NOT invent anything new.
        - Keep responses short and clear.
        RULES;

        return $header . "\n\nUser Question:\n\"{$query}\"\n\nAnswer:\n";
    }
}
