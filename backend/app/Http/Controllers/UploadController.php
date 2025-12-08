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

        // รับ tags ทั้งแบบ array และ comma-separated string
        $tags = $request->input("tags", []);
        if (is_string($tags)) {
            $tags = array_filter(array_map("trim", explode(",", $tags)));
        }

        /**
         * A: files[] (multiple upload)
         */
        if ($request->hasFile("files")) {
            $files = $request->file("files");

            if (!is_array($files)) {
                $files = [$files];
            }

            $paths = [];
            foreach ($files as $file) {
                $fileName = $file->getClientOriginalName();
                $path = $uploadDir . "/" . $fileName;
                $file->move($uploadDir, $fileName);
                $paths[] = $path;

                // ส่งเข้า Queue ต่อไฟล์แต่ละไฟล์
                dispatch(
                    new ProcessUploadJob($path, [
                        "tags" => $tags,
                    ]),
                );

                // ProcessUploadJob::dispatch($path, [
                //     "tags" => $tags,
                // ]);
            }

            return response()->json([
                "status" => "queued",
                "files" => $paths,
                "tags" => $tags,
            ]);
        }

        /**
         * B: file (single upload)
         */
        if ($request->hasFile("file")) {
            $file = $request->file("file");
            $stored = $uploadDir . "/" . $file->getClientOriginalName();
            $file->move($uploadDir, $file->getClientOriginalName());

            dispatch(
                new ProcessUploadJob($stored, [
                    "tags" => $tags,
                ]),
            );

            // ProcessUploadJob::dispatch($stored, [
            //     "tags" => $tags,
            // ]);

            return response()->json([
                "status" => "queued",
                "path" => $stored,
                "tags" => $tags,
            ]);
        }

        /**
         * C: Base64 JSON upload
         */
        if ($request->filled("base64")) {
            $fileName = $request->input("file_name", "uploaded.txt");
            $binaryData = base64_decode($request->base64);

            if ($binaryData === false) {
                return response()->json(["error" => "Invalid base64"], 400);
            }

            $stored = $uploadDir . "/" . $fileName;
            file_put_contents($stored, $binaryData);

            dispatch(
                new ProcessUploadJob($stored, [
                    "tags" => $tags,
                ]),
            );

            // ProcessUploadJob::dispatch($stored, [
            //     "tags" => $tags,
            // ]);

            return response()->json([
                "status" => "queued",
                "path" => $stored,
                "tags" => $tags,
            ]);
        }

        return response()->json(["error" => "no file provided"], 400);
    }

    public function train(Request $request)
    {
        return response()->json(["status" => "train_queued"]);
    }
}
