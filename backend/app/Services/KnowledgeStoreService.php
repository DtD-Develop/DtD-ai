<?php

namespace App\Services;

use App\Services\EmbeddingService;
use App\Services\QdrantService; // ถ้าคุณมี wrapper เอง

class KnowledgeStoreService
{
    public function __construct(
        protected EmbeddingService $embedding,
        protected QdrantService $qdrant,
    ) {}

    public function storeText(string $text, array $tags = []): void
    {
        $embed = $this->embedding->embed($text);

        $this->qdrant->upsert([
            "points" => [
                [
                    "id" => uuid_create(),
                    "vector" => $embed,
                    "payload" => [
                        "tags" => $tags,
                        "text" => $text,
                    ],
                ],
            ],
        ]);
    }
}
