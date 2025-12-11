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
        // FIX 1: fileId → kbFileId
        $kb = KbFile::find($this->kbFileId);
        if (!$kb) {
            return;
        }

        $ollama = new OllamaService();

        // FIX 2: remove chunkIndex + fix file id usage
        $chunks = KbChunk::where("kb_file_id", $this->kbFileId)
            ->orderBy("chunk_index")
            ->get();

        $failures = [];
        foreach ($chunks as $c) {
            $embedding = $ollama->getEmbedding($c->content);
            if (!$embedding) {
                sleep(1);
                $embedding = $ollama->getEmbedding($c->content);
            }

            if (!$embedding) {
                $failures[] = $c->id;
                $c->update(["error" => "embed_failed"]);

                $cur = $kb->error_detail ?? "";
                $cur .= "chunk {$c->id} embed failed; ";
                $kb->update(["error_detail" => $cur, "progress" => 90]);

                continue;
            }

            $pointId = $this->upsertPoint(
                $kb->collection_name,
                $c->id,
                $embedding,
            );

            if (!$pointId) {
                $failures[] = $c->id;

                $c->update(["error" => "qdrant_upsert_failed"]);

                $cur = $kb->error_detail ?? "";
                $cur .= "chunk {$c->id} qdrant_upsert_failed; ";
                $kb->update(["error_detail" => $cur, "progress" => 92]);

                continue;
            }

            $c->update(["point_id" => $pointId, "error" => null]);
        }

        if (count($failures) === 0) {
            $kb->update([
                "progress" => 100,
                "status" => "ready",
                "error" => null,
                "error_detail" => null,
            ]);
        } else {
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
        $host =
            config("services.qdrant.host") ?:
            env("QDRANT_HOST", "http://qdrant:6333");
        $port = config("services.qdrant.port") ?: env("QDRANT_PORT");

        $base = rtrim($host, "/");

        if ($port) {
            $url = "{$base}:{$port}/collections/{$collection}/points?wait=true";
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
            return null;
        }

        if ($res->failed()) {
            return null;
        }

        $json = $res->json();

        if (isset($json["result"]) || isset($json["status"])) {
            return $chunkId;
        }

        return null;
    }
}
