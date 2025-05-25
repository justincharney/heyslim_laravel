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
        Schema::table("questionnaires", function (Blueprint $table) {
            // Add version column, default to 1 for existing records
            $table->unsignedInteger("version")->default(1);
            // Add is_current flag, default to true for existing records
            $table->boolean("is_current")->default(true);

            // Add an index for quick lookup of current questionnaires
            $table->index("is_current");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table("questionnaires", function (Blueprint $table) {
            $table->dropIndex(["is_current"]);
            $table->dropColumn("is_current");
            $table->dropColumn("version");
        });
    }
};
