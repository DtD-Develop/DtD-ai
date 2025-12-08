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

    public function handle()
    {
        $kbFile = KbFile::findOrFail($this->kbFileId);

        // Set status to tagged while analyzing
        $kbFile->update([
            "status" => "tagged",
            "progress" => 70,
        ]);

        // ดึง chunks มาไม่เกิน 20 ชิ้นเพื่อประสิทธิภาพ
        $chunks = KbChunk::where("kb_file_id", $this->kbFileId)
            ->limit(20)
            ->pluck("content")
            ->toArray();

        $collectedKeywords = collect();

        foreach ($chunks as $text) {
            // จำกัดข้อความไม่ให้ยาวเกินไป (LLM safe)
            $text = substr($text, 0, 2000);

            $prompt = "Extract up to 8 clear, meaningful ENGLISH keywords from the text below.
Use single words only. Do not include Thai language.
Return JSON array of strings only (example: [\"logistics\", \"shipping\"]).
Text: \"$text\"";

            $response = Http::post("http://ollama:11434/api/generate", [
                "model" => "llama3.1:8b",
                "prompt" => $prompt,
            ]);

            $keywords = json_decode($response->json("response") ?? "[]", true);

            if (is_array($keywords)) {
                $collectedKeywords = $collectedKeywords->merge($keywords);
            }
        }

        // 🔹 Clean + Unique
        $finalKeywords = $collectedKeywords
            ->map(fn($w) => strtolower(trim($w)))
            ->filter(fn($w) => strlen($w) > 1 && ctype_alpha($w))
            ->unique()
            ->values()
            ->take(10); // Max 10 tags

        $kbFile->update([
            "auto_tags" => $finalKeywords->toArray(),
            "progress" => 90,
        ]);
    }
}
