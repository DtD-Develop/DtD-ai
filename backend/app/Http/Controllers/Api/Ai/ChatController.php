<?php

namespace App\Http\Controllers\Api\Ai;
use App\Http\Controllers\Controller;
use App\Services\Ai\LLM\LLMRouter;
use App\Services\Ai\QueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    /**

     * Create a new controller instance.

     */

    public function __construct(
        protected LLMRouter $llm,

        protected QueryService $queryService,
    ) {}

    /**

     * Simple health check for the AI chat controller.

     *

     * GET /api/ai/health

     */

    public function health(): JsonResponse
    {
        return response()->json([
            "status" => "ok",

            "driver" => config("ai.driver"),
        ]);
    }

    /**

     * Test endpoint for LLM integration.
     *
     * POST /api/ai/test-llm
     *
     * Body (JSON):
     * {
     *   "prompt": "Hello world",
     *   "system_prompt": "You are a helpful assistant",   // optional
     *   "temperature": 0.2,                               // optional
     *   "max_tokens": 256                                 // optional
     * }
     *
     * Response:
     * {
     *   "driver": "local",
     *   "prompt": "...",
     *   "answer": "...",
     *   "duration_ms": 123
     * }
    */

    public function testLlm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            "prompt" => ["required", "string"],

            "system_prompt" => ["nullable", "string"],

            "temperature" => ["nullable", "numeric"],

            "max_tokens" => ["nullable", "integer"],
        ]);

        $payload = [
            "prompt" => $validated["prompt"],

            "system_prompt" => $validated["system_prompt"] ?? null,

            "temperature" => $validated["temperature"] ?? null,

            "max_tokens" => $validated["max_tokens"] ?? null,

            "metadata" => [
                "endpoint" => "/api/ai/test-llm",
            ],
        ];

        $start = microtime(true);

        $answer = $this->llm->generate($payload);

        $durationMs = (int) ((microtime(true) - $start) * 1000);

        return response()->json([
            "driver" => config("ai.driver"),

            "prompt" => $validated["prompt"],

            "answer" => $answer,

            "duration_ms" => $durationMs,
        ]);
    }

    /**
     * Official RAG Chat endpoint for the AI Platform.
     *
     * POST /api/ai/chat
     *
     * Body (JSON), either:
     * {
     *   "question": "What is our shipping policy?"
     * }
     * or
     * {
     *   "messages": [
     *     { "role": "user", "content": "..." },
     *     { "role": "assistant", "content": "..." },
     *     { "role": "user", "content": "..." }
     *   ]
     * }
     *
     * Response (example):
     * {
     *   "answer": "...",
     *   "kb_hits": [ ... ],
     *   "rag_prompt": "..."
     * }
     */
    public function chat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            "question" => ["nullable", "string"],
            "messages" => ["nullable", "array"],
        ]);

        $question = $validated["question"] ?? null;
        $messages = $validated["messages"] ?? null;

        if ($question === null && $messages === null) {
            return response()->json(
                [
                    "message" => 'Either "question" or "messages" is required.',
                ],
                422,
            );
        }

        $payload = [];

        if ($messages !== null) {
            $payload["messages"] = $messages;
        } else {
            $payload["query"] = $question;
        }

        $start = microtime(true);
        $result = $this->queryService->answer($payload);
        $durationMs = (int) ((microtime(true) - $start) * 1000);

        return response()->json([
            "answer" => $result["text"] ?? "",
            "used_kb" => $result["used_kb"] ?? false,
            "hit_count" => $result["hit_count"] ?? 0,
            "sources" => $result["sources"] ?? [],
            "debug" => [
                "duration_ms" => $durationMs,
                "rag_prompt" => $result["rag_prompt"] ?? "",
                "kb_hits" => $result["kb_hits"] ?? [],
            ],
        ]);
    }
}
