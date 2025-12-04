<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table("kb_files", function (Blueprint $table) {
            if (!Schema::hasColumn("kb_files", "summary")) {
                $table->longText("summary")->nullable()->after("tags");
            }
        });
    }

    public function down(): void
    {
        Schema::table("kb_files", function (Blueprint $table) {
            $table->dropColumn("summary");
        });
    }
};
