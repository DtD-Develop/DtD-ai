<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\QueryService;
use App\Services\OllamaService;
use App\Services\AiScoringService;
use App\Services\KnowledgeStoreService;
use App\Jobs\GenerateConversationTitleJob;
use App\Jobs\GenerateConversationSummaryJob;

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

    /* ============================================================
     *  CRUD
     * ============================================================ */
    public function index()
    {
        return Conversation::withCount("messages")
            ->orderBy("created_at", "desc")
            ->get();
    }

    public function storeConversation(Request $req)
    {
        $req->validate(["title" => "nullable|string"]);

        $conversation = Conversation::create([
            "title" => $req->title ?? "",
            "is_title_generated" => false,
        ]);

        return response()->json($conversation, 201);
    }

    public function showConversation(Conversation $conversation)
    {
        $conversation->load([
            "messages" => fn($q) => $q->orderBy("created_at"),
        ]);

        return $conversation;
    }

    public function updateConversation(Request $req, Conversation $conversation)
    {
        $req->validate([
            "title" => "required|string",
            "mode" => "nullable|in:test,train",
        ]);

        $conversation->update([
            "title" => $req->title,
            "is_title_generated" => true,
            "mode" => $req->mode ?? $conversation->mode,
        ]);

        return $conversation;
    }

    public function destroyConversation(Conversation $conversation)
    {
        $conversation->messages()->delete();
        $conversation->delete();
        return response()->json(["status" => "deleted"]);
    }

    /* ============================================================
     *  NON-STREAMING ENDPOINT
     * ============================================================ */
    public function message(Request $req)
    {
        \Log::info("Hit ChatController@message");

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

            if (!$req->conversation_id) {
                $conversation = Conversation::create([
                    "title" => "",
                    "is_title_generated" => false,
                    "mode" => $mode,
                ]);
                $conversationId = $conversation->id;
            } else {
                $conversationId = $req->conversation_id;
                $conversation = Conversation::find($conversationId);
            }

            $userMsg = Message::create([
                "conversation_id" => $conversationId,
                "role" => "user",
                "content" => $question,
                "is_training" => $mode === "train",
            ]);

            $contexts = $this->query->searchKB($question, 4);
            $prompt = $this->buildRagPrompt($question, $contexts);

            $answer = $this->llm->generate($prompt);
            if (!is_string($answer) || trim($answer) === "") {
                $answer = "I’m sorry, I cannot answer right now.";
            }

            $score = null;
            if ($mode === "train") {
                $score = $this->scorer->evaluate($question, $answer);
            }

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

            if ($mode === "train" && $score >= 4) {
                $this->kb->storeText($answer, ["auto_train"]);
            }

            GenerateConversationTitleJob::dispatch($conversationId);
            GenerateConversationSummaryJob::dispatch($conversationId);

            return response()->json([
                "conversation_id" => $conversationId,
                "conversation_mode" => $mode,
                "user_message_id" => $userMsg->id,
                "assistant_message_id" => $assistantMsg->id,
                "answer" => $answer,
                "kb_hits" => $contexts,
                "score" => $score,
            ]);
        } catch (\Throwable $e) {
            \Log::error("ChatController@message exception", [
                "message" => $e->getMessage(),
                "trace" => $e->getTraceAsString(),
            ]);
            return response()->json(
                ["message" => "Internal server error"],
                500,
            );
        }


    /**
     * Rate a message and optionally promote to KB if score is high (Train mode).
     * POST /chat/messages/{message}/rate
     * body: { score: int }
     */
     public function rate(Request $req, Message $message)
     {
         $validator = \Validator::make($req->all(), [
             'score' => 'required|integer|min:0|max:10',
         ]);
         if ($validator->fails()) {
             return response()->json([
                 'message' => 'Validation failed',
                 'errors'  => $validator->errors(),
             ], 400);
         }

         $score = (int) $req->input('score', 0);
         $message->score = $score;
         $message->save();

         return response()->json([
             'message'  => 'Rated',
             'score'    => $score,
             'promoted' => false,
         ]);
     }



    /* ============================================================
     *  STREAMING (NDJSON)
     * ============================================================ */
    public function messageStream(Request $req)
    {
        \Log::info("Hit ChatController@messageStream");

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

            // Create conversation if not exists
            if (!$req->conversation_id) {
                $conversation = Conversation::create([
                    "title" => "",
                    "is_title_generated" => false,
                    "mode" => $mode,
                ]);
                $conversationId = $conversation->id;
            } else {
                $conversationId = $req->conversation_id;
                $conversation = Conversation::find($conversationId);
            }

            // Save user message
            $userMsg = Message::create([
                "conversation_id" => $conversationId,
                "role" => "user",
                "content" => $question,
                "is_training" => $mode === "train",
            ]);

            // KB search
            $contexts = $this->query->searchKB($question, 4);
            $prompt = $this->buildRagPrompt($question, $contexts);

            // Create assistant message
            $assistantMsg = Message::create([
                "conversation_id" => $conversationId,
                "role" => "assistant",
                "content" => "",
                "is_training" => $mode === "train",
                "score" => null,
                "meta" => [
                    "question" => $question,
                    "rag_context" => $contexts,
                ],
            ]);

            return response()->stream(
                function () use (
                    $prompt,
                    $assistantMsg,
                    $conversationId,
                    $userMsg,
                    $mode,
                    $question,
                    $contexts,
                ) {
                    /* -------------------------------------------------------
                     * 1) Send START event so UI can create bubbles
                     * ------------------------------------------------------- */
                    echo json_encode([
                        "type" => "start",
                        "conversation_id" => $conversationId,
                        "user_message_id" => $userMsg->id,
                        "assistant_message_id" => $assistantMsg->id,
                    ]) . "\n";
                    @ob_flush();
                    @flush();

                    $accum = "";

                    /* -------------------------------------------------------
                     * 2) STREAM CHUNKS FROM OLLAMA
                     * ------------------------------------------------------- */
                    $this->llm->streamGenerate($prompt, function ($chunk) use (
                        &$accum,
                        $assistantMsg,
                        $conversationId,
                    ) {
                        $accum .= $chunk;

                        // Update DB
                        try {
                            $assistantMsg->content = $accum;
                            $assistantMsg->saveQuietly();
                        } catch (\Throwable $e) {
                        }

                        echo json_encode([
                            "type" => "chunk",
                            "conversation_id" => $conversationId,
                            "assistant_message_id" => $assistantMsg->id,
                            "chunk" => $chunk,
                        ]) . "\n";
                        @ob_flush();
                        @flush();
                    });

                    /* -------------------------------------------------------
                     * 3) DONE event
                     * ------------------------------------------------------- */
                    $finalAnswer = $assistantMsg->fresh()->content ?? "";
                    $score = null;

                    if ($mode === "train") {
                        $score = $this->scorer->evaluate($question, $finalAnswer);
                        $assistantMsg->score = $score;
                        $assistantMsg->saveQuietly();

                        $threshold = (int) env("DTD_TRAIN_MIN_SCORE", 3);

                        if ($score >= $threshold) {
                            $this->kb->storeText($finalAnswer, ["auto_train"]);
                        }
                    }

                    GenerateConversationTitleJob::dispatch($conversationId);
                    GenerateConversationSummaryJob::dispatch($conversationId);

                    echo json_encode([
                        "type" => "done",
                        "conversation_id" => $conversationId,
                        "assistant_message_id" => $assistantMsg->id,
                        "answer" => $finalAnswer,
                        "score" => $score,
                    ]) . "\n";
                    @ob_flush();
                    @flush();
                },
                200,
                [
                    "Content-Type" => "application/x-ndjson",
                    "Cache-Control" => "no-cache",
                    "X-Accel-Buffering" => "no",
                ],
            );
        } catch (\Throwable $e) {
            \Log::error("ChatController@messageStream exception", [
                "message" => $e->getMessage(),
                "trace" => $e->getTraceAsString(),
            ]);
            return response()->json(
                ["message" => "Internal server error"],
                500,
            );
        }
    }

    /* ============================================================
     *  CONVERSATION SUMMARY
     * ============================================================ */
    public function summarizeConversation(Conversation $conversation)
    {
        GenerateConversationSummaryJob::dispatch($conversation->id);
        return response()->json(["status" => "queued"]);
    }

    /* ============================================================
     *  RAG PROMPT
     * ============================================================ */
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
