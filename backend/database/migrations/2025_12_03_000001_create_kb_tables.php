<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create("kb_files", function (Blueprint $table) {
            $table->id(); // BIGINT unsigned auto-increment (PK)
            $table->string("filename", 512); // ชื่อไฟล์ที่เก็บใน storage
            $table->string("original_name", 512); // ชื่อไฟล์ที่ user อัปโหลด
            $table->string("mime_type", 128)->nullable();
            $table->unsignedBigInteger("size_bytes")->default(0);
            $table->string("storage_path", 1024); // path ใน disk / s3

            $table
                ->enum("status", [
                    "uploaded",
                    "parsing",
                    "tagged",
                    "embedding",
                    "ready",
                    "failed",
                ])
                ->default("uploaded");
            $table->unsignedTinyInteger("progress")->default(0); // 0-100

            $table->json("auto_tags")->nullable(); // tag ที่ model เดาให้
            $table->json("tags")->nullable(); // tag ที่ user ยืนยันแล้ว

            $table->unsignedInteger("chunks_count")->default(0); // จำนวน chunk ที่ embed แล้ว
            $table->text("error_message")->nullable(); // ถ้ามี error

            $table->timestamps();

            // Useful indexes
            $table->index("status");
            $table->index("created_at");
        });

        Schema::create("kb_chunks", function (Blueprint $table) {
            $table->id();
            $table
                ->foreignId("kb_file_id")
                ->constrained("kb_files")
                ->cascadeOnDelete();
            $table->unsignedInteger("chunk_index");
            $table->longText("content");
            $table->string("point_id", 128)->nullable(); // id ของ vector ใน Qdrant (ถ้าอยากเก็บ)
            $table->timestamps();

            // ป้องกันการซ้ำของลำดับชิ้นในไฟล์เดียวกัน
            $table->unique(["kb_file_id", "chunk_index"]);
            $table->index("point_id");
        });

        Schema::create("api_logs", function (Blueprint $table) {
            $table->id();
            $table->string("endpoint", 255); // /api/query, /api/chat/test
            $table->string("method", 16);
            $table->integer("status_code")->nullable();
            $table->integer("latency_ms")->nullable();

            $table->string("ip", 64)->nullable();
            $table->string("api_key", 255)->nullable(); // ไว้ดูว่า key ไหนยิงเยอะ

            $table->json("request_body")->nullable();
            $table->json("response_body")->nullable(); // truncate ให้สั้นก็ได้

            $table->timestamp("created_at")->useCurrent(); // มีเฉพาะ created_at ตามสเปค

            // Useful indexes
            $table->index("endpoint");
            $table->index("api_key");
            $table->index("created_at");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("api_logs");
        Schema::dropIfExists("kb_chunks");
        Schema::dropIfExists("kb_files");
    }
};
