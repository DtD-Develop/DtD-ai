<?php

namespace App\Http\Controllers;

use App\Models\KbFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Jobs\ParseKbFileJob;
use App\Jobs\EmbedKbFileJob;

class KbController extends Controller
{
    /**
     * POST /api/kb/upload
     * multipart/form-data: files[]
     */
    public function upload(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "files" => "required",
            "files.*" => "file|max:512000", // 500MB/ไฟล์ (แล้วแต่คุณจะลด/เพิ่ม)
        ]);

        if ($validator->fails()) {
            return response()->json(
                [
                    "message" => "Validation failed",
                    "errors" => $validator->errors(),
                ],
                422,
            );
        }

        $uploaded = [];

        foreach ($request->file("files") as $file) {
            $extension = $file->getClientOriginalExtension(); // เก็บ .md หรือ .txt
            $randomName = uniqid() . "." . $extension;
            $path = $file->storeAs("uploads", $randomName);

            $kb = KbFile::create([
                "filename" => $randomName,
                "original_name" => $file->getClientOriginalName(),
                "mime_type" => $file->getMimeType(),
                "size_bytes" => $file->getSize(),
                "storage_path" => $path,
                "status" => "uploaded",
                "progress" => 10,
            ]);

            // ส่งเข้า queue ให้ ingest/parse
            dispatch(new ParseKbFileJob($kb->id));
            // ParseKbFileJob::dispatch($kb->id);

            $uploaded[] = $kb;
        }

        return response()->json([
            "message" => "Files uploaded successfully",
            "data" => $uploaded,
        ]);
    }

    /**
     * GET /api/kb/files
     * q, status, per_page
     */
    public function index(Request $request)
    {
        $query = KbFile::query();

        if ($request->filled("q")) {
            $q = $request->get("q");
            $query->where(function ($q2) use ($q) {
                $q2->where("original_name", "like", "%{$q}%")->orWhere(
                    "filename",
                    "like",
                    "%{$q}%",
                );
            });
        }

        if ($request->filled("status")) {
            $query->where("status", $request->get("status"));
        }

        $perPage = min((int) $request->get("per_page", 20), 100);

        return response()->json($query->orderByDesc("id")->paginate($perPage));
    }

    /**
     * GET /api/kb/files/{id}
     */
    public function show($id)
    {
        $kb = KbFile::findOrFail($id);
        return response()->json($kb);
    }

    /**
     * PATCH /api/kb/files/{id}/tags
     * { "tags": [...], "auto_tags": [...] }
     */
    public function updateTags(Request $request, $id)
    {
        $kb = KbFile::findOrFail($id);

        $kb->tags = $request->input("tags", $kb->tags ?? []);
        $kb->auto_tags = $request->input("auto_tags", $kb->auto_tags ?? []);
        $kb->save();

        return response()->json([
            "message" => "Tags updated",
            "data" => $kb,
        ]);
    }

    /**
     * POST /api/kb/files/{id}/confirm
     * กดยืนยันให้เริ่ม embed เข้า Qdrant
     */
    public function confirm($id)
    {
        $kb = KbFile::findOrFail($id);

        // อย่างน้อยควรมี tags สักอย่าง (tags หรือ auto_tags)
        if (empty($kb->tags) && empty($kb->auto_tags)) {
            return response()->json(
                [
                    "message" =>
                        "No tags found. Please set tags before confirming.",
                ],
                422,
            );
        }

        $kb->status = "embedding";
        $kb->progress = 80;
        $kb->save();

        dispatch(new EmbedKbFileJob($kb->id));
        // EmbedKbFileJob::dispatch($kb->id);

        return response()->json([
            "message" => "Embedding started",
            "data" => $kb,
        ]);
    }

    /**
     * DELETE /api/kb/files/{id}
     * ลบทั้งไฟล์ + embeddings (ไว้เพิ่ม logic ลบ Qdrant ภายหลัง)
     */
    public function destroy($id)
    {
        $kb = KbFile::findOrFail($id);

        // ลบไฟล์จริงออกจาก storage
        if ($kb->storage_path && Storage::exists($kb->storage_path)) {
            Storage::delete($kb->storage_path);
        }

        $kb->delete(); // kb_chunks ถูก cascadeOnDelete อยู่แล้ว

        return response()->json(["message" => "KB file deleted"]);
    }

    public function chunks($id)
    {
        $file = KbFile::findOrFail($id);
        return response()->json($file->chunks);
    }

    public function deleteChunk($id, $chunkId)
    {
        $chunk = KbChunk::where("kb_file_id", $id)->findOrFail($chunkId);
        $chunk->delete();

        return response()->json(["status" => "deleted"]);
    }
}
