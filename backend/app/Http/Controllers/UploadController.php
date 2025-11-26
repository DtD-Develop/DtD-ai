<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Jobs\ProcessUploadJob;

class UploadController
{
    public function upload(Request $request)
    {
        // --- Format B: Base64 JSON Upload ---
        if ($request->filled("base64")) {
            $fileName = $request->input("file_name", "uploaded.txt");
            $mimeType = $request->input("mime_type", "text/plain");
            $base64 = $request->input("base64");

            // แปลง Base64 → Binary
            $binaryData = base64_decode($base64);
            if ($binaryData === false) {
                return response()->json(["error" => "Invalid base64"], 400);
            }

            // เก็บไฟล์ลง Storage
            $uploadDir = storage_path("app/uploads");
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $path = $uploadDir . "/" . $fileName;
            file_put_contents($path, $binaryData);

            // Enqueue Background processing job
            ProcessUploadJob::dispatch($path, [
                "source" => $request->input("meta.source"),
                "tags" => $request->input("meta.tags", []),
                "mime_type" => $mimeType,
            ]);

            return response()->json([
                "status" => "queued",
                "path" => $path,
                "fileName" => $fileName,
            ]);
        }

        // --- Format A: Multipart Upload (Compatibility) ---
        if ($request->hasFile("file")) {
            $file = $request->file("file");
            $path = storage_path("app/uploads");
            if (!file_exists($path)) {
                mkdir($path, 0777, true);
            }
            $stored = $path . "/" . $file->getClientOriginalName();
            $file->move($path, $file->getClientOriginalName());

            ProcessUploadJob::dispatch($stored);

            return response()->json([
                "status" => "queued",
                "path" => $stored,
            ]);
        }

        return response()->json(["status" => "no_content"], 400);
    }

    public function train(Request $request)
    {
        return response()->json(["status" => "train_queued"]);
    }
}
