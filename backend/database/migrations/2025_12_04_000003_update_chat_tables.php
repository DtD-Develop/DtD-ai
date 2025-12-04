<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table("conversations", function (Blueprint $table) {
            $table
                ->enum("mode", ["test", "train"])
                ->default("test")
                ->after("user_id");
            $table
                ->timestamp("last_message_at")
                ->nullable()
                ->after("updated_at");
        });

        Schema::table("messages", function (Blueprint $table) {
            $table->tinyInteger("score")->nullable()->after("content"); // 1–5 ดาว
            $table->boolean("is_training")->default(false)->after("score"); // อันนี้ถูกใช้ train KB แล้ว
            $table->json("meta")->nullable()->after("is_training"); // comment, flag อื่น ๆ
            $table->timestamp("rated_at")->nullable()->after("meta");
        });

        Schema::table("kb_files", function (Blueprint $table) {
            $table->string("source", 32)->default("upload")->after("id"); // upload | chat_train
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("messages");
        Schema::dropIfExists("conversations");
    }
};
