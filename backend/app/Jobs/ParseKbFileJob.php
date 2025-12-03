<?php

namespace App\Jobs;

use App\Models\KbFile;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class ParseKbFileJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $kbFileId) {}

    public function handle()
    {
        $kb = KbFile::find($this->kbFileId);
        if (!$kb) {
            return;
        }

        $kb->update([
            "status" => "parsing",
            "progress" => 30,
        ]);

        $resp = Http::timeout(300)->post(env("INGEST_ENDPOINT") . "/parse", [
            "file_path" => storage_path("app/" . $kb->storage_path),
        ]);

        if ($resp->failed()) {
            return $kb->update([
                "status" => "failed",
                "error_message" => $resp->body(),
            ]);
        }

        $data = $resp->json();

        $kb->update([
            "auto_tags" => $data["tags"] ?? [],
            "progress" => 60,
            "status" => "tagged",
        ]);
    }
}
