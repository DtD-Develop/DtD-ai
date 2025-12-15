<?php
namespace App\Services;

use GuzzleHttp\Client;

class QdrantService
{
    protected $client;
    protected $host;
    protected $collection;

    public function __construct()
    {
        // use QDRANT_HTTP_HOST like http://qdrant:6333
        $this->host = rtrim(env("QDRANT_HTTP_HOST", "http://qdrant:6333"), "/");
        $this->client = new Client([
            "base_uri" => $this->host,
            "timeout" => 12,
        ]);
        $this->collection = env("QDRANT_COLLECTION", "dtd_kb");
    }

    /**
     * Hybrid search: vector + optional keyword filter + payload filter
     *
     * $vector: float[]
     * $topK: int
     * $scoreThreshold: float|null
     * $filterPayload: array|null  -> qdrant filter object
     * $keyword: string|null  -> will be used as must match in payload.text (simple)
     */
    public function search(
        array $vector,
        int $topK = 8,
        ?float $scoreThreshold = 0.12,
        ?array $filterPayload = null,
        ?string $keyword = null,
    ) {
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
                // simple payload match: search for keyword in payload.text (Qdrant supports match -> keyword exact)
                // If Qdrant doesn't support full text, you can search with metadata or fallback to application-level filter
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

        $data = json_decode((string) $resp->getBody(), true);
        $result = $data["result"] ?? ($data["points"] ?? []);
        // Normalize result entries
        $items = [];
        foreach ($result as $r) {
            // qdrant returns different shapes; support both
            $payload = $r["payload"] ?? ($r["payload"] ?? []);
            $score = $r["score"] ?? ($r["payload"]["_score"] ?? null);
            $items[] = [
                "id" => $r["id"] ?? null,
                "score" => $r["score"] ?? ($r["payload"]["_score"] ?? null),
                "payload" => $payload,
            ];
        }

        // filter by score threshold if provided
        if ($scoreThreshold !== null) {
            $items = array_filter($items, function ($it) use ($scoreThreshold) {
                $s = $it["score"] ?? 0;
                return $s >= $scoreThreshold;
            });
        }

        return array_values($items);
    }

    /**
     * Upsert point(s) into Qdrant
     * $points : [
     *   ['id'=>str,'vector'=>[], 'payload'=>[]],
     *   ...
     * ]
     */
    public function upsertPoints(array $points)
    {
        $path = "/collections/{$this->collection}/points";
        $body = ["points" => $points];
        $resp = $this->client->put($path, ["json" => $body]);
        return json_decode((string) $resp->getBody(), true);
    }
}
