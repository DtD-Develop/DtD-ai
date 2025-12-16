<?php

namespace App\Services\Ai;

use App\Services\Ai\EmbeddingService;
use App\Services\Ai\QdrantService;

class KnowledgeStoreService
{
    public function __construct(
        protected EmbeddingService $embedding,
        protected QdrantService $qdrant,
    ) {}

    /**
     * Store raw text into the vector store with optional tags.
     *
     * @param string $text
     * @param array<string, mixed> $tags
     */
    public function storeText(string $text, array $tags = []): void
    {
        // NOTE:
        // - Adjust this method name if your EmbeddingService uses a different one
        //   (e.g. getEmbedding() vs embed()).
        // - Here we assume `getEmbedding(string $text): array` like in your current implementation.
        $vector = $this->embedding->getEmbedding($text);

        $this->qdrant->upsert([
            "points" => [
                [
                    "id" => $this->generateId(),
                    "vector" => $vector,
                    "payload" => [
                        "tags" => $tags,
                        "text" => $text,
                    ],
                ],
            ],
        ]);
    }

    /**
     * Generate an ID for the point.
     * Replace with your own UUID implementation if needed.
     */
    protected function generateId(): string|int
    {
        // If you have ramsey/uuid or another lib, you can swap this implementation.
        if (function_exists("uuid_create")) {
            return uuid_create(UUID_TYPE_RANDOM);
        }

        // Fallback: simple unique string (sufficient for demo / POC).
        return (string) hrtime(true);
    }
}
