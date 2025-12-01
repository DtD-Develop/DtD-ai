<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Jobs\ProcessUploadJob;

class UploadController
{
    public function upload(Request $request)
    {
        $uploadDir = storage_path("app/uploads");
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $stored = null;
        $tags = $request->input("tags", []);

        if (is_string($tags)) {
            $tags = array_filter(array_map("trim", explode(",", $tags)));
        }

        /**
         * Format A: Multipart file upload
         */
        if ($request->hasFile("file")) {
            $file = $request->file("file");
            $fileName = $file->getClientOriginalName();

            $stored = $uploadDir . "/" . $fileName;
            $file->move($uploadDir, $fileName);
        }
        /**
         * Format B: Base64 JSON upload
         */ elseif ($request->filled("base64")) {
            $fileName = $request->input("file_name", "uploaded.txt");
            $base64 = $request->input("base64");

            $binaryData = base64_decode($base64);
            if ($binaryData === false) {
                return response()->json(["error" => "Invalid base64"], 400);
            }

            $stored = $uploadDir . "/" . $fileName;
            file_put_contents($stored, $binaryData);
        } else {
            return response()->json(["error" => "no file provided"], 400);
        }

        // --- Trigger ingestion immediately ---
        try {
            ProcessUploadJob::dispatch($stored, [
                "tags" => $tags,
            ]);
        } catch (\Exception $e) {
            return response()->json(
                [
                    "status" => "uploaded",
                    "queued" => false,
                    "error" => $e->getMessage(),
                ],
                500,
            );
        }

        return response()->json([
            "status" => "queued",
            "path" => $stored,
            "tags" => $tags,
        ]);
    }

    public function train(Request $request)
    {
        return response()->json(["status" => "train_queued"]);
    }
}
