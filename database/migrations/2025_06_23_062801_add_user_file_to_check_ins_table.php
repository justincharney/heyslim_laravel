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
        Schema::table("check_ins", function (Blueprint $table) {
            $table
                ->foreignId("user_file_id")
                ->nullable()
                ->constrained("user_files")
                ->onDelete("set null");
            $table->index("user_file_id");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table("check_ins", function (Blueprint $table) {
            $table->dropForeign(["user_file_id"]);
            $table->dropIndex(["user_file_id"]);
            $table->dropColumn("user_file_id");
        });
    }
};
