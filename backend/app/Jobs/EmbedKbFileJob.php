<?php

namespace App\Jobs;

use App\Models\KbFile;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class EmbedKbFileJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $kbFileId) {}

    public function handle()
    {
        $kb = KbFile::find($this->kbFileId);
        if (!$kb) {
            return;
        }

        // กันไว้ เผื่อมี job เก่าเรียกซ้ำ
        $kb->update([
            "status" => "embedding",
            "progress" => 80,
        ]);
        event(new \App\Events\KbFileUpdated($kbFile));

        $ingestUrl = config("services.ingest.url"); // ✅ ใช้ config เดียวกับ Parse

        try {
            $resp = Http::timeout(300)->post($ingestUrl . "/embed", [
                "file_path" => storage_path("app/" . $kb->storage_path),
                "tags" => $kb->tags ?: $kb->auto_tags,
                "kb_file_id" => $kb->id,
            ]);
        } catch (\Throwable $e) {
            // ถ้า network/Qdrant พัง ให้ log ลง DB ด้วย
            $kb->update([
                "status" => "failed",
                "error_message" => $e->getMessage(),
            ]);
            event(new \App\Events\KbFileUpdated($kbFile));

            // ส่งต่อให้ Laravel mark job ว่า failed (จะเห็นใน queue:failed)
            throw $e;
        }

        if ($resp->failed()) {
            return $kb->update([
                "status" => "failed",
                "error_message" => $resp->body(),
            ]);
            event(new \App\Events\KbFileUpdated($kbFile));
        }

        $res = $resp->json();

        $kb->update([
            "chunks_count" => $res["chunks_count"] ?? 0,
            "progress" => 100,
            "status" => "ready",
        ]);
        event(new \App\Events\KbFileUpdated($kbFile));
    }
}
