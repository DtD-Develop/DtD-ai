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

use App\Jobs\AnalyzeKbFileJob;

class ParseKbFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $kbFileId) {}

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

        // Call ingest service
        $resp = Http::timeout(300)->post(
            config("services.ingest.url") . "/parse",
            [
                "file_path" => storage_path("app/" . $kb->storage_path),
            ],
        );

        if ($resp->failed()) {
            return $kb->update([
                "status" => "failed",
                "error_message" => $resp->body(),
            ]);
        }

        $data = $resp->json();
        $tags = $data["tags"] ?? [];
        $chunks = $data["chunks"] ?? [];

        // Remove old chunks
        KbChunk::where("kb_file_id", $kb->id)->delete();

        // Save chunks
        foreach ($chunks as $chunk) {
            KbChunk::create([
                "kb_file_id" => $kb->id,
                "chunk_index" => $chunk["i"],
                "content" => $chunk["text"],
            ]);
        }

        // Update KB
        $kb->update([
            "auto_tags" => $tags,
            "chunks_count" => count($chunks),
            "status" => "tagged",
            "progress" => count($chunks) > 0 ? 65 : 50,
        ]);

        dispatch(new AnalyzeKbFileJob($kb->id));
    }
}
