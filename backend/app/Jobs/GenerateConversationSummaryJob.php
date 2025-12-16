<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\Ai\LLM\LLMRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * GenerateConversationSummaryJob
 *
 * Summarizes a conversation into:
 *  - One short paragraph
 *  - Three bullet points of key facts / decisions / action items
 *
 * Uses the LLMRouter abstraction so that the underlying model
 * (local GPU model, Gemini, etc.) can be selected automatically
 * based on task metadata and configuration.
 */
class GenerateConversationSummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Conversation ID to summarize.
     */
    public int $conversationId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $conversationId)
    {
        $this->conversationId = $conversationId;
    }

    /**
     * Handle the job.
     *
     * This implementation uses the generic LLMRouter instead of a concrete
     * engine so that the underlying model (local / Gemini / etc.)
     * can be switched via configuration and routing policy.
     */
    public function handle(LLMRouter $llm): void
    {
        /** @var Conversation|null $conv */
        $conv = Conversation::with("messages")->find($this->conversationId);

        if (!$conv) {
            return;
        }

        // If there are no messages, nothing to summarize.
        $msgs = $conv->messages()->orderBy("created_at")->get();
        if ($msgs->isEmpty()) {
            return;
        }

        // Join last N messages into a single text block (limit content length).
        $texts = $msgs
            ->map(function ($m) {
                $role = strtoupper($m->role);
                $content = trim((string) $m->content);
                // Hard cap per-message length so one message doesn't explode the prompt.
                $content = mb_substr($content, 0, 2000);

                return "{$role}: {$content}";
            })
            ->slice(-20) // last 20 messages
            ->implode("\n\n");
        $prompt = <<<PROMPT
        You are a helpful assistant. Given the conversation below, produce:

        1) A short summary in one concise paragraph.
        2) Three bullet points of key facts, decisions, or action items.

        Rules:
        - Use Thai or English according to the conversation language.
        - Be concise and clear.
        - The result should be suitable as a conversation summary in a dashboard.

        Conversation:
        {$texts}

        Summary and bullet points:
        PROMPT;

        $summary = trim(
            $llm->generate([
                "prompt" => $prompt,
                "metadata" => [
                    "job" => "GenerateConversationSummaryJob",
                    "conv" => $this->conversationId,
                    "task" => "kb_summary", // routed as KB/summary-type task
                    "source" => "GenerateConversationSummaryJob",
                ],
            ]),
        );

        if ($summary === "") {
            return;
        }

        $conv->summary = $summary;
        $conv->save();
    }
}
