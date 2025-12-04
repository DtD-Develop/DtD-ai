<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create("conversations", function (Blueprint $table) {
            $table->id();
            $table->string("title")->nullable();
            $table->string("user_id", 255)->nullable(); // เราจะใช้ API_KEY ที่มาจาก middleware
            $table->timestamps();

            $table->index("user_id");
            $table->index("created_at");
        });

        Schema::create("messages", function (Blueprint $table) {
            $table->id();
            $table
                ->foreignId("conversation_id")
                ->constrained("conversations")
                ->cascadeOnDelete();
            $table->enum("role", ["user", "assistant"]);
            $table->longText("content");
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("messages");
        Schema::dropIfExists("conversations");
    }
};
