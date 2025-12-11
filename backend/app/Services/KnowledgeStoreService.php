<?php

namespace App\Services;

use Qdrant;

class KnowledgeStoreService
{
    public function storeText($text, $tags = [])
    {
        $embed = app(EmbeddingService::class)->embed($text);

        Qdrant::upsert([
            "points" => [
                [
                    "id" => uuid_create(),
                    "vector" => $embed,
                    "payload" => [
                        "text" => $text,
                        "tags" => $tags,
                        "source" => "training",
                    ],
                ],
            ],
        ]);
    }
}
