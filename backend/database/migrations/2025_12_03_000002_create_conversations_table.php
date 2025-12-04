<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create("conversations", function (Blueprint $table) {
            $table->id();
            $table->string("title")->nullable();
            $table->string("user_id", 64)->nullable(); // Supply chain worker id?
            $table->timestamps();
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists("failed_jobs");
    }
};
