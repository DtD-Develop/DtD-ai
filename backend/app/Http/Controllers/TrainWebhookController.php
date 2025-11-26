<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;

class TrainWebhookController
{
    public function handle(Request $request)
    {
        $payload = $request->all();
        // เขียน log ลง storage/app/train_logs/YYYYMMDD.log (append แบบ JSON per line)
        $dir = storage_path("app/train_logs");
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $file = $dir . "/" . date("Ymd") . ".log";
        $line = json_encode(
            [
                "time" => date("c"),
                "status" => $payload["status"] ?? "unknown",
                "fileId" => $payload["fileId"] ?? null,
                "fileName" => $payload["fileName"] ?? null,
                "extra" => $payload,
            ],
            JSON_UNESCAPED_UNICODE,
        );
        file_put_contents($file, $line . PHP_EOL, FILE_APPEND);

        // เขียนลง laravel.log เพื่อ debug ด้วย
        \Log::info("train-webhook", $payload);

        return response()->json(["ok" => true]);
    }
}
