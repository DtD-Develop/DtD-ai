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

        // mark embedding
        $kb->update([
            "status" => "embedding",
            "progress" => 85,
        ]);

        // load chunks
        $chunks = KbChunk::where("kb_file_id", $kb->id)
            ->orderBy("chunk_index")
            ->get();

        if ($chunks->count() === 0) {
            return $kb->update([
                "status" => "failed",
                "error_message" => "No chunks to embed",
            ]);
        }

        // Send to ingest_service /embed for actual embedding
        $resp = Http::timeout(300)->post(
            config("services.ingest.url") . "/embed",
            [
                "file_path" => storage_path("app/" . $kb->storage_path),
                "tags" => $kb->tags ?: [],
                "kb_file_id" => $kb->id,
            ],
        );

        if ($resp->failed()) {
            return $kb->update([
                "status" => "failed",
                "error_message" => "Embed service failed: " . $resp->body(),
            ]);
        }

        $count = $resp->json("chunks_count") ?? 0;

        $kb->update([
            "progress" => 100,
            "status" => "ready",
            "error" => null,
        ]);
    }
}
