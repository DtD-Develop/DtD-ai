<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use App\Models\Document;
use App\Models\DocumentChunk;
use Exception;

class ProcessUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $filePath;
    protected $tags;

    public function __construct($filePath, $tags = [])
    {
        $this->filePath = $filePath;
        $this->tags = $tags;
    }

    public function handle()
    {
        $content = Storage::get($this->filePath);
        $fileName = basename($this->filePath);

        // Save document meta
        $document = Document::create([
            "name" => $fileName,
            "path" => $this->filePath,
            "tags" => $this->tags,
        ]);

        // Split into chunks
        $chunks = $this->splitText($content);

        foreach ($chunks as $chunk) {
            // Embed text using ingest service
            $embedResponse = Http::timeout(30)->post(
                env("INGEST_URL") . "/embed",
                [
                    "text" => $chunk,
                ],
            );

            if (
                !$embedResponse->successful() ||
                !isset($embedResponse["vector"])
            ) {
                throw new Exception(
                    "Embedding failed: " . $embedResponse->body(),
                );
            }

            $vector = $embedResponse["vector"];

            // Store in DB
            $documentChunk = DocumentChunk::create([
                "document_id" => $document->id,
                "chunk_text" => $chunk,
            ]);

            // Store vector in Qdrant
            $pointId = "doc_{$documentChunk->id}";
            $qdrantResponse = Http::timeout(30)->post(
                env("QDRANT_URL") .
                    "/collections/" .
                    env("QDRANT_COLLECTION") .
                    "/points",
                [
                    "points" => [
                        [
                            "id" => $pointId,
                            "vector" => $vector,
                            "payload" => [
                                "document_id" => $document->id,
                                "chunk_id" => $documentChunk->id,
                                "tags" => $this->tags,
                            ],
                        ],
                    ],
                ],
            );

            if (!$qdrantResponse->successful()) {
                throw new Exception(
                    "Qdrant store failed: " . $qdrantResponse->body(),
                );
            }

            $documentChunk->update(["qdrant_point_id" => $pointId]);
        }
    }

    private function splitText($text)
    {
        $text = str_replace("\r\n", "\n", $text);
        $chunks = preg_split('/\n\s*\n/', $text); // split paragraph
        return array_filter(array_map("trim", $chunks));
    }
}
