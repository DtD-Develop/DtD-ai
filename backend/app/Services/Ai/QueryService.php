<?php

namespace App\Services\Ai;

use App\Services\Ai\EmbeddingService;
use App\Services\Ai\LLM\LLMRouter;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class QueryService
{
    protected string $qdrantUrl;
    protected string $qdrantCollection;

    public function __construct(
        protected EmbeddingService $embeddingService,
        protected LLMRouter $llm,
    ) {
        $this->qdrantUrl = (string) env("QDRANT_URL", "http://qdrant:6333");
        $this->qdrantCollection = (string) env("QDRANT_COLLECTION", "kb");
    }

    /**
     * Main entry: accepts payload with either 'messages' (chat) or 'query' string.
     *
     * Returns:
     *  [
     *    'text'       => string,            // final LLM answer
     *    'kb_hits'    => array<int,array>,  // raw hits from Qdrant
     *    'rag_prompt' => string,            // prompt sent to LLM
     *  ]
     *
     * Example $payload:
     *  [ 'conversation_id' => 1, 'query' => 'Who is CEO of ShipD2D?' ]
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function answer(array $payload): array
    {
        $messages = $payload["messages"] ?? null;
        $query = $payload["query"] ?? null;
        $systemPrompt = $payload["system_prompt"] ?? null;

        if ($messages === null) {
            if ($query === null) {
                throw new \InvalidArgumentException(
                    'Either "messages" or "query" is required.',
                );
            }

            $messages = [
                [
                    "role" => "user",
                    "content" => $query,
                ],
            ];
        }

        // Using last user turn for KB search
        $lastUser = $this->extractLastUserMessage($messages);

        // 1) Search KB
        $kbHits = $this->searchKB($lastUser, 4);

        // 2) Extract text snippets for prompt
        $contextTexts = $this->extractContextTexts($kbHits);

        // 3) Build RAG prompt
        $ragPrompt = $this->buildKbPrompt($lastUser, $contextTexts);

        // 3.1) If we have a system prompt (e.g. memory), prepend it
        $finalPrompt = $ragPrompt;
        if (is_string($systemPrompt) && trim($systemPrompt) !== "") {
            $finalPrompt = trim($systemPrompt) . "\n\n" . $ragPrompt;
        }

        // Decide task type for router metadata
        // If there are KB hits, treat as kb_answer, otherwise generic chat
        $task = count($kbHits) > 0 ? "kb_answer" : "chat";

        // 4) Call LLM via router
        $answerText = $this->llm->generate([
            "prompt" => $finalPrompt,
            "metadata" => [
                "task" => $task,
                "source" => "QueryService",
            ],
        ]);

        $sources = $this->mapHitsToSources($kbHits);

        return [
            "text" => $answerText,
            "kb_hits" => $kbHits,
            "sources" => $sources,
            "rag_prompt" => $ragPrompt,
            "used_kb" => count($kbHits) > 0,
            "hit_count" => count($kbHits),
        ];
    }

    /**
     * Search Qdrant collection for top-k relevant points.
     *
     * @return array<int,array<string,mixed>>
     */
    public function searchKB(string $queryText, int $topK = 4): array
    {
        // 1) create embedding for query using EmbeddingService
        $embed = $this->embeddingService->getEmbedding($queryText);

        if (!$embed || !is_array($embed) || count($embed) === 0) {
            Log::warning("Embedding missing when searching KB", [
                "query" => $queryText,
            ]);

            return [];
        }

        $url =
            rtrim($this->qdrantUrl, "/") .
            "/collections/{$this->qdrantCollection}/points/search";

        try {
            $response = \Http::timeout(15)->post($url, [
                "vector" => $embed,
                "limit" => $topK,
                "with_payload" => true,
                "with_vector" => false,
            ]);

            if ($response->failed()) {
                Log::error("Qdrant search failed", [
                    "status" => $response->status(),
                    "body" => $response->body(),
                ]);

                return [];
            }

            $json = $response->json();

            /** @var array<int,array<string,mixed>> $result */
            $result = Arr::get($json, "result", []);

            return $result;
        } catch (\Throwable $e) {
            Log::error("Qdrant search exception", [
                "err" => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Extract last user message from a messages array.
     *
     * @param  array<int,array<string,mixed>>  $messages
     */
    protected function extractLastUserMessage(array $messages): string
    {
        $lastUser = null;

        foreach (array_reverse($messages) as $m) {
            if (($m["role"] ?? "") === "user") {
                $lastUser = $m["content"] ?? null;
                break;
            }
        }

        if ($lastUser === null) {
            $lastUser = $messages[0]["content"] ?? "";
        }

        return (string) $lastUser;
    }

    /**
     * Extract plain text snippets from Qdrant hits to use in prompt.
     *
     * @param  array<int,array<string,mixed>>  $kbHits
     * @return array<int,string>
     */
    protected function extractContextTexts(array $kbHits): array
    {
        $contextTexts = [];

        foreach ($kbHits as $hit) {
            $payload = $hit["payload"] ?? [];

            $text =
                $payload["text"] ??
                ($payload["content"] ?? ($hit["payload"] ?? ""));

            $contextTexts[] = trim((string) $text);
        }

        return $contextTexts;
    }

    /**
     * Map raw Qdrant hits into a simplified "source" structure for UI/training.
     *
     * @param  array<int,array<string,mixed>>  $kbHits
     * @return array<int,array<string,mixed>>
     */
    protected function mapHitsToSources(array $kbHits): array
    {
        $sources = [];

        foreach ($kbHits as $hit) {
            $payload = $hit["payload"] ?? [];

            $sources[] = [
                "id" => $hit["id"] ?? null,
                "score" => $hit["score"] ?? null,
                "text" => isset($payload["text"])
                    ? trim((string) $payload["text"])
                    : "",
                "metadata" => [
                    "filename" => $payload["filename"] ?? null,
                    "source" => $payload["source"] ?? null,
                    "tags" => $payload["tags"] ?? null,
                ],
            ];
        }

        return $sources;
    }

    /**
     * Build an English RAG prompt using the context texts.
     *
     * @param  array<int,string>  $contextTexts
     */
    protected function buildKbPrompt(string $query, array $contextTexts): string
    {
        $ctx = count($contextTexts)
            ? implode("\n\n---\n\n", $contextTexts)
            : "";

        $prompt = <<<PROMPT
        You are an AI assistant that answers ONLY using the information provided in the Knowledge Base context below.

        [KNOWLEDGE BASE CONTEXT]
        {$ctx}
        [END OF CONTEXT]

        Rules:
        - If the answer is not found in the context, reply exactly: "I don't have this information in the knowledge base."
        - Do NOT invent any information that is not explicitly included in the context.
        - Keep answers concise, factual, and well-structured.
        - If relevant, mention which snippet index (1,2,...) you used from the context.
        - If the context is empty, briefly say you could not find relevant information in the KB.

        User Question:
        "{$query}"

        Provide the final answer below:
        PROMPT;

        return $prompt;
    }
}
