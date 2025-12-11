<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Arr;
use Psr\Log\LoggerInterface;

class QueryService
{
    protected string $qdrantUrl;
    protected string $qdrantCollection;
    protected string $ollamaUrl;
    protected string $ollamaModel;
    protected ?LoggerInterface $logger;

    public function __construct(LoggerInterface $logger = null)
    {
        $this->qdrantUrl = env("QDRANT_URL", "http://qdrant:6333");
        $this->qdrantCollection = env("QDRANT_COLLECTION", "kb"); // adjust to your collection name
        $this->ollamaUrl = env("OLLAMA_URL", "http://ollama:11434");
        $this->ollamaModel = env("OLLAMA_MODEL", "llama3.1:8b");
        $this->logger = $logger;
    }

    /**
     * Main entry: accepts payload with either 'messages' (chat) or 'query' string.
     * Returns ['text' => answer, 'kb_hits' => [...], 'rag_prompt' => '...']
     *
     * Example $payload:
     *  [ 'conversation_id' => 1, 'query' => 'Who is CEO of ShipD2D?' ]
     */
    public function answer(array $payload): array
    {
        $conversationId = $payload["conversation_id"] ?? null;
        $messages = $payload["messages"] ?? null;
        $query = $payload["query"] ?? null;

        if ($messages === null) {
            if ($query === null) {
                throw new \InvalidArgumentException(
                    'Either "messages" or "query" is required.',
                );
            }
            $messages = [["role" => "user", "content" => $query]];
        }

        // Using last user turn for KB search
        $lastUser = null;
        foreach (array_reverse($messages) as $m) {
            if (($m["role"] ?? "") === "user") {
                $lastUser = $m["content"] ?? null;
                break;
            }
        }
        if ($lastUser === null) {
            // fallback to first message content
            $lastUser = $messages[0]["content"] ?? "";
        }

        // 1) Search KB
        $kbHits = $this->searchKB($lastUser, 4);

        // Extract text snippets for prompt
        $contextTexts = [];
        foreach ($kbHits as $hit) {
            // payload may differ, try common fields
            $payload = $hit["payload"] ?? [];
            $text =
                $payload["text"] ??
                ($payload["content"] ?? ($hit["payload"] ?? ""));
            $contextTexts[] = trim($text);
        }

        // 2) Build RAG prompt (English)
        $ragPrompt = $this->buildKbPrompt($lastUser, $contextTexts);

        // 3) Call LLM via OllamaService
        $ollama = app(OllamaService::class);
        $answerText = $ollama->generate($ragPrompt);

        return [
            "text" => $answerText,
            "kb_hits" => $kbHits,
            "rag_prompt" => $ragPrompt,
        ];
    }

    /**
     * Search Qdrant collection for top-k relevant points.
     * Return array of raw hits (id, score, payload).
     */
    public function searchKB(string $queryText, int $topK = 4): array
    {
        // 1) create embedding for query (use OllamaService or OpenAI fallback there)
        $embed = app(OllamaService::class)->getEmbedding($queryText);

        if (!$embed || !is_array($embed) || count($embed) === 0) {
            $this->logger?->warning("Embedding missing when searching KB", [
                "query" => $queryText,
            ]);
            return [];
        }

        // 2) Call Qdrant search
        $url =
            rtrim($this->qdrantUrl, "/") .
            "/collections/{$this->qdrantCollection}/points/search";

        try {
            $res = Http::timeout(15)->post($url, [
                "vector" => $embed,
                "limit" => $topK,
                "with_payload" => true,
                "with_vector" => false,
            ]);

            if ($res->failed()) {
                $this->logger?->error("Qdrant search failed", [
                    "status" => $res->status(),
                    "body" => $res->body(),
                ]);
                return [];
            }

            $json = $res->json();
            return Arr::get($json, "result", []);
        } catch (\Throwable $e) {
            $this->logger?->error("Qdrant search exception", [
                "err" => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Build an English RAG prompt using the context texts.
     */
    protected function buildKbPrompt(string $query, array $contextTexts): string
    {
        $ctx = count($contextTexts)
            ? implode("\n\n---\n\n", $contextTexts)
            : "";

        $prompt = <<<PROMPT
        You are an AI assistant that answers ONLY using the information provided in the Knowledge Base below.

        [KNOWLEDGE BASE CONTEXT]
        {$ctx}
        [END OF CONTEXT]

        Rules:
        - If the answer is not found in the context, reply exactly: "I don't have this information in the knowledge base."
        - Do NOT invent any information that is not explicitly included in the context.
        - Keep answers concise, factual, and well-structured.
        - If relevant, cite which part(s) of the context your answer is based on by referencing the snippet index (1,2,...).
        - If context is empty, briefly say you could not find relevant information in the KB.

        User Question:
        "{$query}"

        Provide the final answer below:
        PROMPT;

        return $prompt;
    }
}
