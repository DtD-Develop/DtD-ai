<?php

namespace App\Jobs;

use App\Models\KbFile;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

use App\Jobs\AnalyzeKbFileJob;

class ParseKbFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $kbFileId) {}

    // public function handle()
    // {
    //     $kb = KbFile::find($this->kbFileId);
    //     if (!$kb) {
    //         return;
    //     }

    //     $kb->update([
    //         "status" => "parsing",
    //         "progress" => 30,
    //     ]);

    //     $resp = Http::timeout(300)->post(env("INGEST_ENDPOINT") . "/parse", [
    //         "file_path" => storage_path("app/" . $kb->storage_path),
    //     ]);

    //     if ($resp->failed()) {
    //         return $kb->update([
    //             "status" => "failed",
    //             "error_message" => $resp->body(),
    //         ]);
    //     }

    //     $kb->update([
    //         "progress" => 50,
    //     ]);

    //     $parseResult = $resp->json();
    //     $autoTags = $parseResult["tags"] ?? [];

    //     // หลังจากอ่านไฟล์ + chunk + auto_tag เสร็จ
    //     $chunks = $parseResult["chunks"] ?? [];
    //     $texts = collect($chunks)
    //         ->pluck("text")
    //         ->map(fn($t) => trim($t))
    //         ->all();
    //     $fullText = implode("\n\n---\n\n", $texts);

    //     // สร้าง summary prompt แบบ TL;DR + Bullet list
    //     $prompt = <<<PROMPT
    //     สรุปเนื้อหาในไฟล์นี้ให้อยู่ในรูปแบบ:

    //     TL;DR:
    //     - สรุปเนื้อหาหลักไม่เกิน 3-4 ประโยค

    //     Key Points:
    //     - ใส่ bullet point 4-8 ข้อที่อธิบายสิ่งสำคัญในไฟล์
    //     - กระชับ ชัดเจน ไม่ลากยาว

    //     [CONTENT START]
    //     {$fullText}
    //     [CONTENT END]
    //     PROMPT;

    //     $summaryRes = Http::post(env("OLLAMA_URL") . "/api/generate", [
    //         "model" => env("OLLAMA_MODEL", "llama3.1:8b"),
    //         "prompt" => $prompt,
    //         "stream" => false,
    //     ]);

    //     $summary = $summaryRes->json()["response"] ?? null;

    //     $kb->update([
    //         "summary" => $summary,
    //         "progress" => 60,
    //         "status" => "tagged",
    //     ]);

    //     $this->release(1);
    //     dispatch(new AnalyzeKbFileJob($this->kbFileId));
    // }
    //
    public function handle()
    {
        $kb = KbFile::find($this->kbFileId);
        if (!$kb) {
            return;
        }

        $kb->update([
            "status" => "parsing",
            "progress" => 30,
        ]);

        $resp = Http::timeout(300)->post(env("INGEST_ENDPOINT") . "/parse", [
            "file_path" => storage_path("app/" . $kb->storage_path),
        ]);

        if ($resp->failed()) {
            return $kb->update([
                "status" => "failed",
                "error_message" => $resp->body(),
            ]);
        }

        $parseResult = $resp->json();
        $ingestTags = $parseResult["tags"] ?? null;

        // create summary (optional)
        $kb->update([
            "auto_tags" => $ingestTags ?: [],
            "status" => "tagged",
            "progress" => $ingestTags ? 65 : 50, // 🚀 If has tags → skip ahead
        ]);

        dispatch(new AnalyzeKbFileJob($kb->id));
    }
}
