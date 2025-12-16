<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table("api_logs", function (Blueprint $table) {
            // Add after latency_ms for readability (you can adjust position)
            $table->string("llm_driver")->nullable()->after("latency_ms");
            $table->string("llm_task")->nullable()->after("llm_driver");
        });
    }

    public function down(): void
    {
        Schema::table("api_logs", function (Blueprint $table) {
            $table->dropColumn("llm_driver");
            $table->dropColumn("llm_task");
        });
    }
};
