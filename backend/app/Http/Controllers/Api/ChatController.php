<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\QueryService;
use App\Services\LLMService;
use App\Models\Message;

class ChatController extends Controller
{
    protected $query;
    protected $llm;

    public function __construct(QueryService $query, LLMService $llm)
    {
        $this->query = $query;
        $this->llm = $llm;
    }

    /**
     * POST /api/chat/message
     * body: { conversation_id, message, mode: 'test'|'train' }
     */
    public function message(Request $req)
    {
        $req->validate([
            "conversation_id" => "required|integer",
            "message" => "required|string",
            "mode" => "nullable|in:test,train",
        ]);

        $question = $req->message;
        // 1) Retrieve top-k contexts from KB
        $topK = 4;
        $contexts = $this->query->search($question, $topK); // returns array of ['id','text','score','payload']

        // 2) Build RAG prompt
        $prompt = $this->buildRagPrompt($question, $contexts);

        // 3) Call LLM with prompt
        $answer = $this->llm->chatCompletion($prompt);

        // 4) Save message + rag context
        $msg = Message::create([
            "conversation_id" => $req->conversation_id,
            "question" => $question,
            "answer" => $answer,
            "mode" => $req->mode ?? "test",
            "rag_context" => json_encode($contexts),
        ]);

        return response()->json([
            "answer" => $answer,
            "contexts" => $contexts,
            "message_id" => $msg->id,
        ]);
    }

    protected function buildRagPrompt(string $question, array $contexts): string
    {
        $header =
            "You are an assistant that answers questions using the provided knowledge snippets. If the answer isn't contained in the snippets, say you don't know.\n\n";
        if (count($contexts) === 0) {
            $header .= "No relevant knowledge snippets found.\n\n";
        } else {
            $header .= "Relevant knowledge snippets:\n";
            foreach ($contexts as $i => $c) {
                $text = trim($c["text"] ?? ($c["payload"]["text"] ?? ""));
                $header .= "[" . ($i + 1) . "] " . $text . "\n\n";
            }
        }

        $header .= "User question:\n\"{$question}\"\n\n";
        $header .=
            "Answer concisely and cite snippet numbers where relevant.\n";
        return $header;
    }
}
