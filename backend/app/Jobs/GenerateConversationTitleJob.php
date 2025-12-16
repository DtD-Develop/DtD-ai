<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Ai\LLM\LLMRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateConversationTitleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The conversation ID for which we want to generate a title.
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

     * Handle the job using the generic LLMRouter abstraction.
     *

     * This allows the underlying LLM engine (local/Ollama, Gemini, etc.)

     * to be selected and switched via configuration and routing policy.
     */
    public function handle(LLMRouter $llm): void
    {
        /** @var Conversation|null $conv */
        $conv = Conversation::with("messages")->find($this->conversationId);

        if (!$conv) {
            return;
        }

        // If the title has already been generated or manually set, do nothing.
        if ($conv->is_title_generated) {
            return;
        }

        // Take the first few user messages as the basis for the title.
        $userTexts = $conv
            ->messages()
            ->where("role", "user")
            ->orderBy("id")
            ->take(5)
            ->pluck("content")
            ->toArray();

        if (empty($userTexts)) {
            return;
        }

        $joined = implode("\n", $userTexts);

        $prompt = <<<PROMPT

        From the text below, create a concise conversation room title in English that:

        - Is short, no more than 5 words
        - Captures the main topic of the discussion
        - Does NOT include words like "chat" or "conversation"
        - Does NOT include quotation marks
        - Is suitable to be shown as a chat room name for the user

        Text:
        {$joined}

        Conversation room title:
        PROMPT;

        $rawTitle = $llm->generate([
            "prompt" => $prompt,

            "metadata" => [
                "job" => "GenerateConversationTitleJob",

                "conv" => $this->conversationId,

                "task" => "title_generation",
                "source" => "GenerateConversationTitleJob",
            ],
        ]);

        $title = $this->normalizeTitle($rawTitle);

        if ($title === "") {
            return;
        }

        $conv->title = $title;
        $conv->is_title_generated = true;
        $conv->save();
    }

    /**
     * Normalize the raw LLM output into a clean title string.
     */
    protected function normalizeTitle(string $raw): string
    {
        $title = trim($raw);

        // If the model wraps the title in quotes, remove them.
        if (
            (str_starts_with($title, '"') && str_ends_with($title, '"')) ||
            (str_starts_with($title, "“") && str_ends_with($title, "”")) ||
            (str_starts_with($title, "'") && str_ends_with($title, "'"))
        ) {
            $title = mb_substr($title, 1, mb_strlen($title) - 2);
            $title = trim($title);
        }

        // If the model returns multiple lines or bullet points, keep only the first line.
        if (str_contains($title, "\n")) {
            $title = trim(strtok($title, "\n"));
        }

        // Extra safety: strip surrounding quotes again if remained.
        $title = trim($title, "\"'“”");

        return $title;
    }
}
