<?php

namespace App\Services\Ai;

use GuzzleHttp\Client;

class EmbeddingService
{
    protected Client $client;
    protected string $base;

    public function __construct()
    {
        $this->base = env("INGEST_SERVICE_BASE", "http://ingest_service:8000");

        $this->client = new Client([
            "base_uri" => $this->base,
            "timeout" => 10,
        ]);
    }

    /**
     * Get embedding vector for a piece of text from ingest_service /embed-text
     *
     * @return array<float>
     */
    public function getEmbedding(string $text): array
    {
        $resp = $this->client->post("/embed-text", [
            "json" => ["text" => $text],
        ]);

        $data = json_decode((string) $resp->getBody(), true);

        if (!isset($data["vector"])) {
            throw new \RuntimeException("No vector from embed service");
        }

        return $data["vector"];
    }
}
