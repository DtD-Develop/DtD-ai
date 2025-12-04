<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table("conversations", function (Blueprint $table) {
            if (!Schema::hasColumn("conversations", "is_title_generated")) {
                $table
                    ->boolean("is_title_generated")
                    ->default(false)
                    ->after("mode");
            }
        });
    }

    public function down(): void
    {
        Schema::table("conversations", function (Blueprint $table) {
            $table->dropColumn("is_title_generated");
        });
    }
};
