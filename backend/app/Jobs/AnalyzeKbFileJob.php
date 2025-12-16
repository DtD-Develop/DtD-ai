<?php

namespace App\Jobs;

use App\Models\KbChunk;
use App\Models\KbFile;
use App\Services\Ai\LLM\LLMRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AnalyzeKbFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The ID of the KB file to analyze.
     */
    public int $kbFileId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $kbFileId)
    {
        $this->kbFileId = $kbFileId;
    }

    /**
     * Handle the job.
     *
     * This job:
     *  - Loads the KB file and its chunks
     *  - Uses the LLMRouter to auto-extract keywords (tags)
     *  - Uses the LLMRouter to generate a short summary
     */
    public function handle(LLMRouter $llm): void
    {
        /** @var KbFile|null $kb */
        $kb = KbFile::find($this->kbFileId);

        if (!$kb) {
            return;
        }

        $chunks = KbChunk::where("kb_file_id", $kb->id)
            ->orderBy("chunk_index")
            ->get();

        if ($chunks->count() === 0) {
            $kb->update([
                "progress" => 70,
                "summary" => null,
            ]);

            return;
        }

        // Build input text (first few chunks) for summary
        $summaryText = $chunks->take(8)->pluck("content")->implode("\n\n");

        // Hard cap the text length to avoid overly long prompts
        $summaryText = mb_substr($summaryText, 0, 20000);

        // Build text for tagging (smaller sample)
        $textForTag = $chunks->implode("content", " ");
        $textForTag = mb_substr($textForTag, 0, 4000);

        // ------------------------------------------------------------------
        // 1) Auto-tag using LLMRouter
        // ------------------------------------------------------------------

        $tagPrompt = <<<PROMPT
        Extract important English keywords from the following text.
        Return ONLY a valid JSON array of strings.

        Example:
        ["logistics", "shipping", "warehouse"]

        TEXT:
        {$textForTag}
        PROMPT;

        $keywords = $this->extractKeywordsUsingLlm($llm, $tagPrompt);

        // ------------------------------------------------------------------
        // 2) Build summary using LLMRouter
        // ------------------------------------------------------------------

        $summaryPrompt = <<<PROMPT
        You are a helpful assistant. Read the following document content
        and produce a concise summary in one short paragraph, followed by
        3 bullet points of key ideas or facts.

        Keep the language consistent with the document (Thai or English).
        Keep it clear and suitable for a knowledge base.

        DOCUMENT:
        {$summaryText}

        SUMMARY:
        PROMPT;

        $summary = $this->generateSummaryUsingLlm($llm, $summaryPrompt);

        $kb->update([
            "auto_tags" => $keywords,
            "summary" => $summary ?: "(Summary not available)",
            "status" => "tagged",
            "progress" => 75,
        ]);
    }

    /**
     * Use the LLMRouter to extract keywords as a JSON array of strings.
     *
     * @return array<int,string>
     */
    protected function extractKeywordsUsingLlm(
        LLMRouter $llm,
        string $prompt,
    ): array {
        $raw = $llm->generate([
            "prompt" => $prompt,
            "metadata" => [
                "job" => "AnalyzeKbFileJob",
                "task" => "kb_auto_tag",
                "source" => "AnalyzeKbFileJob",
            ],
        ]);

        $raw = trim($raw);

        // Try to extract JSON array from raw output
        $jsonString = $this->extractJsonArray($raw);

        $decoded = json_decode($jsonString, true);

        if (!is_array($decoded)) {
            return [];
        }

        // Normalize: lowercase, trim, filter short/empty, unique, limit count
        $keywords = collect($decoded)
            ->filter(fn($w) => is_string($w) && mb_strlen(trim($w)) > 1)
            ->map(fn($w) => mb_strtolower(trim($w)))
            ->unique()
            ->take(10)
            ->values()
            ->all();

        /** @var array<int,string> $keywords */
        return $keywords;
    }

    /**
     * Use the LLMRouter to generate a summary string.
     */
    protected function generateSummaryUsingLlm(
        LLMRouter $llm,
        string $prompt,
    ): ?string {
        $raw = $llm->generate([
            "prompt" => $prompt,
            "metadata" => [
                "job" => "AnalyzeKbFileJob",
                "task" => "kb_summary",
                "source" => "AnalyzeKbFileJob",
            ],
        ]);

        $summary = trim($raw);

        if ($summary === "") {
            return null;
        }

        return $summary;
    }

    /**
     * Best-effort extraction of a JSON array (e.g. ["a","b"]) from LLM output.
     *
     * @return string
     */
    protected function extractJsonArray(string $text): string
    {
        $text = trim($text);

        // If it is already a valid JSON array, return directly.
        $decoded = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $text;
        }

        // Try to locate first '[' and last ']' and treat inside as array.
        $start = strpos($text, "[");
        $end = strrpos($text, "]");

        if ($start !== false && $end !== false && $end > $start) {
            $candidate = substr($text, $start, $end - $start + 1);
            $decoded = json_decode($candidate, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $candidate;
            }
        }

        // Fallback: return original text, caller will see decode failure.
        return $text;
    }
}
