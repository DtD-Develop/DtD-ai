
<?php namespace App\Jobs;

use App\Models\Message;
use App\Models\KbFile;
use App\Services\Ai\LLM\LLMRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * PromoteChatMessageToKbJob
 *
 * This job takes an assistant message from a chat conversation,
 * asks the LLM to convert the Q&A into a short knowledge article,
 * saves it as a KB file, and triggers embedding.
 *
 * It now uses the generic LLMAdapter so the underlying model
 * (local Ollama, Gemini, etc.) can be switched via configuration.
 */
class PromoteChatMessageToKbJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The ID of the assistant message to promote.
     */
    public int $messageId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $messageId)
    {
        $this->messageId = $messageId;
    }

    /**

     * Handle the job using the generic LLMRouter.
     */
    public function handle(LLMRouter $llm): void
    {
        /** @var Message $msg */
        $msg = Message::with(["conversation", "conversation.messages"])->find(
            $this->messageId,
        );

        if (!$msg) {
            return;
        }

        // Guard: only promote assistant messages that haven't been used for training yet
        if ($msg->is_training || $msg->role !== "assistant") {
            return;
        }

        $conversation = $msg->conversation;
        if (!$conversation) {
            return;
        }

        // Find the last user message before or at this assistant message
        $userMsg = $conversation
            ->messages()
            ->where("role", "user")
            ->where("id", "<=", $msg->id)
            ->orderByDesc("id")
            ->first();

        $question = $userMsg?->content ?? "";
        $answer = (string) $msg->content;

        if (trim($answer) === "" && trim($question) === "") {
            return;
        }

        // Prompt the LLM to convert the Q&A into a KB-style article

        $prompt = <<<PROMPT

        Transform the Q&A below into a short, concise knowledge article suitable for storing in a Knowledge Base.

        Guidelines:
        - Do not mention that this came from a chat or question-answer.
        - Do not include any personal names.
        - Write as a direct knowledge article in a neutral tone.
        - If there are steps or lists, format them as clear bullet points or numbered steps for readability.

        [Question]
        {$question}

        [Answer]
        {$answer}
        PROMPT;

        $kbText = $llm->generate([
            "prompt" => $prompt,

            "metadata" => [
                "job" => "PromoteChatMessageToKbJob",

                "message_id" => $this->messageId,

                "conversation_id" => $conversation->id,

                "task" => "training_to_kb",
                "source" => "PromoteChatMessageToKbJob",
            ],
        ]);

        if (!is_string($kbText) || trim($kbText) === "") {
            return;
        }

        $kbText = trim($kbText);

        // Determine storage path under storage/app/...
        $relativeDir = config("dtd.chat_train_dir", "kb-chat-train");
        $storageDir = storage_path("app/" . $relativeDir);

        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0775, true);
        }

        $storagePath = $relativeDir . "/chat_" . $msg->id . ".txt";
        $fullPath = storage_path("app/" . $storagePath);

        file_put_contents($fullPath, $kbText);

        // Create KbFile record for this promoted content
        $kb = KbFile::create([
            "source" => "chat_train",
            "filename" => basename($storagePath),
            "original_name" =>
                "chat-train-" . $conversation->id . "-" . $msg->id . ".txt",
            "mime_type" => "text/plain",
            "size_bytes" => strlen($kbText),
            "storage_path" => $storagePath,
            "status" => "embedding", // or 'uploaded' if you want a separate parse step
            "progress" => 80,
            "auto_tags" => null,
            "tags" => null,
        ]);

        // Trigger embedding pipeline immediately (same behavior as before)
        dispatch(new \App\Jobs\EmbedKbFileJob($kb->id));

        // Mark that this message has been used for training / promotion
        $msg->is_training = true;
        $msg->save();
    }
}
