<?php
use GuzzleHttp\Client;
use Illuminate\Support\Str;

class ProcessUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $filePath;
    protected string $source;
    protected array $tags;

    public function __construct($filePath, $meta = [])
    {
        $this->filePath = $filePath;
        $this->source = $meta["source"] ?? "upload";
        $this->tags = $meta["tags"] ?? [];
    }

    public function handle()
    {
        $qdrantUrl = env("QDRANT_URL", "http://qdrant:6333");
        $qdrantCollection = env("QDRANT_COLLECTION", "company_kb");
        $ollamaUrl = env("OLLAMA_URL", "http://ollama:11434");
        $embedModel = env("EMBED_MODEL", "nomic-embed-text");

        $qdrant = new Client(["base_uri" => $qdrantUrl, "timeout" => 30]);
        $ollama = new Client(["base_uri" => $ollamaUrl, "timeout" => 120]);

        // 📝 1) Extract text
        $text = $this->extractText($this->filePath);
        if (!$text) {
            \Log::error("No content extracted from: {$this->filePath}");
            return;
        }

        // 🔁 2) Chunking
        $chunks = $this->chunkText($text);

        // 📌 doc id for grouping
        $docId = Str::uuid()->toString();

        // 🔥 3) Embedding + Upsert Qdrant
        foreach ($chunks as $idx => $chunk) {
            $vec = $this->embedText($ollama, $embedModel, $chunk);

            $qdrant->put("/collections/{$qdrantCollection}/points", [
                "json" => [
                    "points" => [
                        [
                            "id" => Str::uuid()->toString(),
                            "vector" => $vec,
                            "payload" => [
                                "text" => $chunk,
                                "source" => $this->source,
                                "tags" => $this->tags,
                                "doc_id" => $docId,
                                "chunk_idx" => $idx,
                            ],
                        ],
                    ],
                ],
            ]);
        }

        // 🛎 4) Notify webhook
        Http::post(url("/api/train-webhook"), [
            "status" => "completed",
            "fileName" => basename($this->filePath),
            "fileId" => $docId,
        ]);
    }

    private function embedText(
        Client $ollama,
        string $model,
        string $txt,
    ): array {
        $res = $ollama->post("/api/embeddings", [
            "json" => [
                "model" => $model,
                "input" => $txt,
            ],
        ]);
        $js = json_decode($res->getBody(), true);
        return $js["embedding"] ?? [];
    }

    private function extractText(string $path): ?string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($ext, ["txt", "md"])) {
            return file_get_contents($path);
        }

        if ($ext === "pdf") {
            return shell_exec(
                "pdftotext -layout " . escapeshellarg($path) . " -",
            );
        }

        if (
            ($ext === "doc" || $ext === "docx") &&
            shell_exec("which soffice")
        ) {
            $tmp = storage_path("app/tmp/" . uniqid() . ".pdf");
            shell_exec(
                "soffice --headless --convert-to pdf " .
                    escapeshellarg($path) .
                    " --outdir " .
                    escapeshellarg(dirname($tmp)),
            );
            return shell_exec(
                "pdftotext -layout " . escapeshellarg($tmp) . " -",
            );
        }

        return file_get_contents($path);
    }

    private function chunkText(string $txt, int $size = 1000): array
    {
        $txt = preg_replace("/\s+/", " ", $txt);
        return str_split($txt, $size);
    }
}
