<?php

namespace App\Jobs;

use App\Models\KbFile;
use App\Models\KbChunk;
use App\Services\OllamaService;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class AnalyzeKbFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $kbFileId) {}

    public function handle()
    {
        $kb = KbFile::find($this->kbFileId);
        if (!$kb) {
            return;
        }

        $chunks = KbChunk::where("kb_file_id", $kb->id)
            ->orderBy("chunk_index")
            ->get();

        if ($chunks->count() === 0) {
            return $kb->update([
                "progress" => 70,
                "summary" => null,
            ]);
        }

        // Build input text (first few chunks)
        $summaryText = $chunks->take(8)->pluck("content")->implode("\n\n");
        $summaryText = substr($summaryText, 0, 20000);

        // Auto-tag using MLLM
        $textForTag = substr($chunks->implode("content", " "), 0, 4000);

        $prompt = <<<PROMPT
        Extract important English keywords from the text.
        Return ONLY a JSON array of strings. Example: ["logistics", "shipping"]

        TEXT:
        "$textForTag"
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

        // Build summary with OllamaService
        $ollama = new OllamaService();
        $summary = $ollama->summarizeText($summaryText, 300);

        $kb->update([
            "auto_tags" => $keywords,
            "summary" => $summary ?: "(Summary not available)",
            "status" => "tagged",
            "progress" => 75,
        ]);
    }
}
