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
        $kb = KbFile::find($this->fileId);
        if (!$kb) {
            return;
        }

        $ollama = new OllamaService();

        $chunks = KbChunk::where("kb_file_id", $this->fileId)
            ->when(
                $this->chunkIndex !== null,
                fn($q) => $q->where("chunk_index", $this->chunkIndex),
            )
            ->orderBy("chunk_index")
            ->get();

        $failures = [];
        foreach ($chunks as $c) {
            $embedding = $ollama->getEmbedding($c->content);
            if (!$embedding) {
                // try retry once
                sleep(1);
                $embedding = $ollama->getEmbedding($c->content);
            }

            if (!$embedding) {
                $failures[] = $c->id;
                // mark chunk error for debugging
                $c->update(["error" => "embed_failed"]);
                // log details into kb error_detail (append)
                $cur = $kb->error_detail ?? "";
                $cur .= "chunk {$c->id} embed failed; ";
                $kb->update(["error_detail" => $cur, "progress" => 90]);
                // continue to next chunk (do not abort whole process)
                continue;
            }

            $pointId = $this->upsertPoint(
                $kb->collection_name,
                $c->id,
                $embedding,
            );
            if ($pointId === null) {
                $failures[] = $c->id;
                $c->update(["error" => "qdrant_upsert_failed"]);
                $cur = $kb->error_detail ?? "";
                $cur .= "chunk {$c->id} qdrant_upsert_failed; ";
                $kb->update(["error_detail" => $cur, "progress" => 92]);
                continue;
            }

            $c->update(["point_id" => $pointId, "error" => null]);
        }

        // set final status
        if (count($failures) === 0) {
            $kb->update([
                "progress" => 100,
                "status" => "ready",
                "error" => null,
                "error_detail" => null,
            ]);
        } else {
            // partial success
            $kb->update([
                "progress" => 98,
                "status" => "partial",
                "error" => "partial_embed",
            ]);
        }
    }
}
