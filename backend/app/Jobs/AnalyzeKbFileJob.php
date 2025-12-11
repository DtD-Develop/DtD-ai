<?php

namespace App\Jobs;

use App\Models\KbChunk;
use App\Models\KbFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class AnalyzeKbFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 min
    public $tries = 3;

    public function __construct(public int $kbFileId) {}

    //     public function handle()
    //     {
    //         $kbFile = KbFile::findOrFail($this->kbFileId);

    //         // Set status to tagged while analyzing
    //         $kbFile->update([
    //             "status" => "tagged",
    //             "progress" => 70,
    //         ]);

    //         // ดึง chunks มาไม่เกิน 20 ชิ้นเพื่อประสิทธิภาพ
    //         $chunks = KbChunk::where("kb_file_id", $this->kbFileId)
    //             ->limit(20)
    //             ->pluck("content")
    //             ->toArray();

    //         $collectedKeywords = collect();

    //         foreach ($chunks as $text) {
    //             // จำกัดข้อความไม่ให้ยาวเกินไป (LLM safe)
    //             $text = substr($text, 0, 2000);

    //             $prompt = "Extract up to 8 clear, meaningful ENGLISH keywords from the text below.
    // Use single words only. Do not include Thai language.
    // Return JSON array of strings only (example: [\"logistics\", \"shipping\"]).
    // Text: \"$text\"";

    //             $response = Http::post("http://ollama:11434/api/generate", [
    //                 "model" => "llama3.1:8b",
    //                 "prompt" => $prompt,
    //             ]);

    //             $keywords = json_decode($response->json("response") ?? "[]", true);

    //             if (is_array($keywords)) {
    //                 $collectedKeywords = $collectedKeywords->merge($keywords);
    //             }
    //         }

    //         // 🔹 Clean + Unique
    //         $finalKeywords = $collectedKeywords
    //             ->map(fn($w) => strtolower(trim($w)))
    //             ->filter(fn($w) => strlen($w) > 1 && ctype_alpha($w))
    //             ->unique()
    //             ->values()
    //             ->take(10); // Max 10 tags

    //         $kbFile->update([
    //             "auto_tags" => $finalKeywords->toArray(),
    //             "progress" => 90,
    //         ]);
    //     }
    //
    public function handle()
    {
        $kb = KbFile::find($this->kbFileId);
        if (!$kb) {
            return;
        }

        // If has ingester tags, do nothing
        if (!empty($kb->auto_tags)) {
            return $kb->update([
                "progress" => 70,
            ]);
        }

        $kb->update([
            "progress" => 65,
        ]);

        $chunks = \App\Models\KbChunk::where("kb_file_id", $kb->id)
            ->limit(20)
            ->pluck("content")
            ->toArray();

        if (count($chunks) === 0) {
            return $kb->update([
                "progress" => 70,
            ]);
        }

        $text = substr(implode(" ", $chunks), 0, 3000);

        $prompt = <<<PROMPT
        Extract meaningful English keywords from the following text.
        Return ONLY a JSON array of strings. No Thai.
        Text: "$text"
        PROMPT;

        $res = Http::post(env("OLLAMA_URL") . "/api/generate", [
            "model" => env("OLLAMA_MODEL", "llama3.1:8b"),
            "prompt" => $prompt,
        ]);

        $keywords = json_decode($res->json("response") ?? "[]", true);

        $keywords = collect($keywords)
            ->map(fn($w) => strtolower(trim($w)))
            ->filter(fn($w) => strlen($w) > 1 && ctype_alpha($w))
            ->unique()
            ->take(10)
            ->values()
            ->toArray();

        $chunks = KbChunk::where("kb_file_id", $kb->id)
            ->orderBy("chunk_index")
            ->limit(10)
            ->pluck("content")
            ->toArray();

        $textForSummary = implode("\n\n", $chunks);
        // truncate if very long
        if (strlen($textForSummary) > 20000) {
            $textForSummary = substr($textForSummary, 0, 19000);
        }

        $ollama = new OllamaService();
        $summary = $ollama->summarizeText($textForSummary, 300);

        $kb->update([
            "auto_tags" => $keywords,
            "summary" => $summary,
            "progress" => 75, // adjust
        ]);

        // optionally dispatch embed job
        dispatch(new \App\Jobs\EmbedKbFileJob($kb->id));
    }
}
