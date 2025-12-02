<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create("documents", function (Blueprint $table) {
            $table->id();
            $table->string("name");
            $table->string("path")->nullable();
            $table->json("tags")->nullable();
            $table->timestamps();
        });

        Schema::create("document_chunks", function (Blueprint $table) {
            $table->id();
            $table
                ->foreignId("document_id")
                ->constrained("documents")
                ->cascadeOnDelete();
            $table->longText("chunk_text");
            $table->string("qdrant_point_id")->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("document_chunks");
        Schema::dropIfExists("documents");
    }
};
