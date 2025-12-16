<?php

namespace App\Services\Ai;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class QdrantService
{
    protected Client $client;
    protected string $host;
    protected string $collection;

    public function __construct()
    {
        // use QDRANT_HTTP_HOST like http://qdrant:6333
        $this->host = rtrim(
            (string) env("QDRANT_HTTP_HOST", "http://qdrant:6333"),
            "/",
        );

        $this->client = new Client([
            "base_uri" => $this->host,
            "timeout" => 12,
        ]);

        $this->collection = (string) env("QDRANT_COLLECTION", "dtd_kb");
    }

    /**
     * Hybrid search: vector + optional keyword filter + payload filter
     *
     * @param  array<int,float>        $vector
     * @param  int                     $topK
     * @param  float|null              $scoreThreshold
     * @param  array<string,mixed>|null $filterPayload  Qdrant filter object
     * @param  string|null             $keyword         Simple keyword match on payload.text
     * @return array<int,array<string,mixed>>
     *
     * @throws GuzzleException
     */
    public function search(
        array $vector,
        int $topK = 8,
        ?float $scoreThreshold = 0.12,
        ?array $filterPayload = null,
        ?string $keyword = null,
    ): array {
        $body = [
            "vector" => $vector,
            "limit" => $topK,
            "with_payload" => true,
            "with_vector" => false,
        ];

        // Qdrant v1.1+ uses /collections/{name}/points/search
        $path = "/collections/{$this->collection}/points/search";

        // Build filter: combine provided filter + keyword (match in payload.text) if given
        if ($filterPayload || $keyword) {
            $must = [];

            if ($filterPayload) {
                $must[] = $filterPayload;
            }

            if ($keyword) {
                // Simple payload match: search for keyword in payload.text
                $must[] = [
                    "key" => "text",
                    "match" => ["value" => $keyword],
                ];
            }

            $body["filter"] = ["must" => $must];
        }

        $resp = $this->client->post($path, [
            "json" => $body,
        ]);

        /** @var array<string,mixed> $data */
        $data = json_decode((string) $resp->getBody(), true);

        /** @var array<int,array<string,mixed>> $result */
        $result = $data["result"] ?? ($data["points"] ?? []);

        // Normalize result entries
        $items = [];

        foreach ($result as $r) {
            /** @var array<string,mixed> $payload */
            $payload = $r["payload"] ?? [];

            $score = $r["score"] ?? ($payload["_score"] ?? null);

            $items[] = [
                "id" => $r["id"] ?? null,
                "score" => $score,
                "payload" => $payload,
            ];
        }

        // Filter by score threshold if provided
        if ($scoreThreshold !== null) {
            $items = array_values(
                array_filter($items, static function (array $it) use (
                    $scoreThreshold,
                ): bool {
                    $s = (float) ($it["score"] ?? 0.0);

                    return $s >= $scoreThreshold;
                }),
            );
        }

        return $items;
    }

    /**
     * Upsert point(s) into Qdrant.
     *
     * @param  array<int,array<string,mixed>> $points [
     *   ['id'=>string|int,'vector'=>array<int,float>, 'payload'=>array<string,mixed>],
     *   ...
     * ]
     * @return array<string,mixed>
     *
     * @throws GuzzleException
     */
    public function upsertPoints(array $points): array
    {
        $path = "/collections/{$this->collection}/points";

        $body = ["points" => $points];

        $resp = $this->client->put($path, ["json" => $body]);

        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $resp->getBody(), true);

        return $decoded;
    }
}
