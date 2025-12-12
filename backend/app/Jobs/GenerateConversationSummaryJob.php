<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\OllamaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateConversationSummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $conversationId;

    public function __construct(int $conversationId)
    {
        $this->conversationId = $conversationId;
    }

    public function handle(OllamaService $ollama)
    {
        $conv = Conversation::with("messages")->find($this->conversationId);
        if (!$conv) {
            return;
        }

        // prepare text: last N messages (user+assistant)
        $msgs = $conv->messages()->orderBy("created_at")->get();
        if ($msgs->isEmpty()) {
            return;
        }

        // join messages into a prompt (limit to last ~2000 chars or N messages)
        $texts = $msgs
            ->map(function ($m) {
                $role = $m->role;
                $content = trim(substr($m->content ?? "", 0, 2000));
                return strtoupper($role) . ": " . $content;
            })
            ->slice(-20)
            ->implode("\n\n"); // last 20 messages

        $prompt = <<<PROMPT
        You are a helpful assistant. Given the conversation below, return a short summary (one paragraph, Thai or English depending on content) and 3 bullet points of key facts or action items. Keep it concise.

        Conversation:
        {$texts}

        Summary:
        PROMPT;

        $summary = trim($ollama->generate($prompt));
        if ($summary !== "") {
            $conv->summary = $summary;
            $conv->save();
        }
    }
}
