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

    private function upsertPoint(
        string $collection,
        int $chunkId,
        array $embedding,
    ) {
        // อ่าน config (ปรับชื่อ config/env ตามโปรเจกต์ของคุณ)
        $host =
            config("services.qdrant.host") ?:
            env("QDRANT_HOST", "http://qdrant:6333");
        $port = config("services.qdrant.port") ?: env("QDRANT_PORT", null);

        // Build URL
        $base = rtrim($host, "/");
        if ($port) {
            // if host already contains port, avoid double
            if (
                strpos($base, ":") === false ||
                preg_match("/http(s)?:\\/\\//", $base)
            ) {
                $url = "{$base}:{$port}/collections/{$collection}/points?wait=true";
            } else {
                $url = "{$base}/collections/{$collection}/points?wait=true";
            }
        } else {
            $url = "{$base}/collections/{$collection}/points?wait=true";
        }

        $body = [
            "points" => [
                [
                    "id" => $chunkId,
                    "vector" => $embedding,
                    "payload" => ["chunk_id" => $chunkId],
                ],
            ],
        ];

        try {
            $res = Http::post($url, $body);
        } catch (\Throwable $e) {
            // network error
            return null;
        }

        if ($res->failed()) {
            return null;
        }

        $json = $res->json();
        // Qdrant usually returns result -> { operation_id, status } or result -> { points: ... }
        if (
            !empty($json) &&
            (isset($json["result"]) || isset($json["status"]))
        ) {
            return $chunkId;
        }

        return null;
    }
}
