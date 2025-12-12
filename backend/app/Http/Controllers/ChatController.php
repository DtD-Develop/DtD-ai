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

    /* Conversation CRUD (same as before) */
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
            "mode" => "nullable|in:test,train",
        ]);

        $conversation->update([
            "title" => $req->title,
            "is_title_generated" => true,
            // optional mode on conversation
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

    /* ---------------------------------------------------------
     *  MAIN CHAT ENDPOINT (non-streaming)
     *  POST /api/chat/message
     * --------------------------------------------------------- */
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

            // create conversation if missing
            $conversationId = $req->conversation_id;
            if (!$conversationId) {
                $conversation = Conversation::create([
                    "title" => "",
                    "is_title_generated" => false,
                    "mode" => $mode,
                ]);
                $conversationId = $conversation->id;
            } else {
                $conversation = Conversation::find($conversationId);
            }

            // Save user message first (needed for title gen)
            $userMsg = Message::create([
                "conversation_id" => $conversationId,
                "role" => "user",
                "content" => $question,
                "is_training" => $mode === "train",
            ]);

            // Search KB
            $contexts = $this->query->searchKB($question, 4);

            // Build prompt and call LLM
            $prompt = $this->buildRagPrompt($question, $contexts);
            $answer = $this->llm->generate($prompt);
            if (!is_string($answer) || trim($answer) === "") {
                $answer = "I’m sorry, I cannot answer right now.";
            }

            // Score if train mode
            $score = null;
            if ($mode === "train") {
                $score = $this->scorer->evaluate($question, $answer);
            }

            // Save assistant message
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

            // Auto add to KB if train + good score
            if ($mode === "train" && $score >= 4) {
                $this->kb->storeText($answer, ["auto_train"]);
            }

            // Dispatch title generator and summary generator
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
    }

    /* ---------------------------------------------------------
     *  Streaming endpoint (chunked streaming via Fetch readable stream)
     *  POST /api/chat/message/stream
     *  Body: { conversation_id?, message, mode? }
     * --------------------------------------------------------- */
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

        // We'll stream raw text chunks to client using chunked response
        try {
            $mode = $req->mode ?? "test";
            $question = $req->message;
            $conversationId = $req->conversation_id;
            if (!$conversationId) {
                $conversation = Conversation::create([
                    "title" => "",
                    "is_title_generated" => false,
                    "mode" => $mode,
                ]);
                $conversationId = $conversation->id;
            } else {
                $conversation = Conversation::find($conversationId);
            }

            // save user message
            $userMsg = Message::create([
                "conversation_id" => $conversationId,
                "role" => "user",
                "content" => $question,
                "is_training" => $mode === "train",
            ]);

            // search KB
            $contexts = $this->query->searchKB($question, 4);
            $prompt = $this->buildRagPrompt($question, $contexts);

            // create assistant DB row first with empty content so we can update as chunks come
            $assistantMsg = Message::create([
                "conversation_id" => $conversationId,
                "role" => "assistant",
                "content" => "",
                "score" => null,
                "is_training" => $mode === "train",
                "meta" => [
                    "question" => $question,
                    "rag_context" => $contexts,
                ],
            ]);

            // Prepare stream response
            // We'll send newline-delimited JSON chunks for the client to parse
            return response()->stream(
                function () use (
                    $prompt,
                    $conversationId,
                    $assistantMsg,
                    $mode,
                    $question,
                    $contexts,
                ) {
                    // The OllamaService->streamGenerate should yield pieces (or accept a callback)
                    // Here assume it accepts a closure that will be called with each chunk.
                    $accum = "";
                    $this->llm->streamGenerate($prompt, function ($chunk) use (
                        &$accum,
                        $assistantMsg,
                        $conversationId,
                    ) {
                        // append to DB incrementally (optional: throttle updates)
                        $accum .= $chunk;
                        // update assistant message with partial content (non-blocking)
                        try {
                            $assistantMsg->content = $accum;
                            $assistantMsg->saveQuietly();
                        } catch (\Throwable $e) {
                            // ignore DB errors on incremental updates
                        }

                        // send chunk to client as JSON line
                        $payload = json_encode([
                            "type" => "chunk",
                            "conversation_id" => $conversationId,
                            "assistant_message_id" => $assistantMsg->id,
                            "chunk" => $chunk,
                        ]);
                        echo $payload . "\n";
                        // flush to client
                        @ob_flush();
                        @flush();
                    });

                    // after streaming finished, compute final answer and optionally score & KB save
                    $finalAnswer = $assistantMsg->fresh()->content ?? "";
                    $score = null;
                    if ($mode === "train") {
                        $score = $this->scorer->evaluate(
                            $question,
                            $finalAnswer,
                        );
                        $assistantMsg->score = $score;
                        $assistantMsg->saveQuietly();

                        if ($score >= 4) {
                            $this->kb->storeText($finalAnswer, ["auto_train"]);
                        }
                    }

                    // dispatch jobs
                    GenerateConversationTitleJob::dispatch($conversationId);
                    GenerateConversationSummaryJob::dispatch($conversationId);

                    // final payload
                    $final = json_encode([
                        "type" => "done",
                        "conversation_id" => $conversationId,
                        "assistant_message_id" => $assistantMsg->id,
                        "answer" => $finalAnswer,
                        "score" => $score,
                    ]);
                    echo $final . "\n";
                    @ob_flush();
                    @flush();
                },
                200,
                [
                    "Content-Type" => "application/x-ndjson",
                    "Cache-Control" => "no-cache",
                    "X-Accel-Buffering" => "no", // for nginx buffering
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

    /* ---------------------------------------------------------
     *  Conversation summarization endpoint (manual trigger)
     *  POST /api/chat/conversations/{conversation}/summarize
     * --------------------------------------------------------- */
    public function summarizeConversation(Conversation $conversation)
    {
        GenerateConversationSummaryJob::dispatch($conversation->id);
        return response()->json(["status" => "queued"]);
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
