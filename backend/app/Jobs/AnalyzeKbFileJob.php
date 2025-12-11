<?php

namespace App\Jobs;

use App\Models\KbFile;
use App\Models\KbChunk;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\OllamaService;

class AnalyzeKbFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $kbFileId;

    public function __construct(int $kbFileId)
    {
        $this->kbFileId = $kbFileId;
    }

    public function handle()
    {
        $kb = KbFile::find($this->kbFileId);
        if (!$kb) {
            return;
        }

        // ------- Step 1: Load chunks -------
        $chunks = KbChunk::where("kb_file_id", $kb->id)
            ->orderBy("chunk_index")
            ->get();

        if ($chunks->count() === 0) {
            return $kb->update([
                "progress" => 70,
            ]);
        }

        // ------- Step 2: Build text for tag extraction -------
        $text = substr($chunks->implode("content", " "), 0, 4000);

        $prompt = <<<PROMPT
        Extract important English keywords from the following text.
        Return ONLY a JSON array of strings. Example: ["logistics", "shipping"]

        Text:
        "$text"
        PROMPT;

        $res = Http::post(env("OLLAMA_URL") . "/api/generate", [
            "model" => env("OLLAMA_MODEL", "llama3.1:8b"),
            "prompt" => $prompt,
        ]);

        $keywords = json_decode($res->json("response") ?? "[]", true);

        $keywords = collect($keywords)
            ->map(fn($w) => strtolower(trim($w)))
            ->filter(fn($w) => strlen($w) > 1)
            ->unique()
            ->take(10)
            ->values()
            ->toArray();

        // ------- Step 3: Build summary text from first few chunks -------
        $summaryText = $chunks->take(8)->pluck("content")->implode("\n\n");

        // Limit to reasonable token size
        if (strlen($summaryText) > 20000) {
            $summaryText = substr($summaryText, 0, 20000);
        }

        // ------- Step 4: Generate summary -------
        $ollama = new OllamaService();
        $summary = $ollama->summarizeText($summaryText, 300);

        // ------- Step 5: Save both auto-tags + summary -------
        $kb->update([
            "auto_tags" => $keywords,
            "summary" => $summary,
            "progress" => 75,
        ]);

        // ------- Step 6: Continue to Embed Job -------
        dispatch(new \App\Jobs\EmbedKbFileJob($kb->id));
    }
}
