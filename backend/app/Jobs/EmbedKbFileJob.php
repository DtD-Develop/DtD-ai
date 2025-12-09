<?php

namespace App\Jobs;

use App\Models\KbFile;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

use App\Jobs\AnalyzeKbFileJob;

class EmbedKbFileJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $kbFileId) {}

    public function handle()
    {
        $kb->update([
            "status" => "embedding",
            "progress" => 80,
        ]);

        $kb = KbFile::find($this->kbFileId);
        if (!$kb) {
            return;
        }

        $resp = Http::timeout(300)->post(
            config("services.ingest.url") . "/embed",
            [
                "file_path" => storage_path("app/" . $kb->storage_path),
                "tags" => $kb->tags ?: $kb->auto_tags,
                "kb_file_id" => $kb->id,
            ],
        );

        if ($resp->failed()) {
            return $kb->update([
                "status" => "failed",
                "error_message" => $resp->body(),
            ]);
        }

        $res = $resp->json();

        $kb->update([
            "chunks_count" => $res["chunks_count"] ?? 0,
            "progress" => 100,
            "status" => "ready",
        ]);
    }
}
