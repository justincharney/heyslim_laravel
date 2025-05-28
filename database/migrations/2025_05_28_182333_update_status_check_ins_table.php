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
            // Drop the existing status column
            $table->dropColumn("status");
        });

        Schema::table("check_ins", function (Blueprint $table) {
            // Add the new status column with updated enum values
            $table
                ->enum("status", [
                    "pending",
                    "submitted",
                    "reviewed",
                    "skipped",
                    "cancelled",
                ])
                ->default("pending");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table("check_ins", function (Blueprint $table) {
            // Drop the new status column
            $table->dropColumn("status");
            // Add back the original status column
            $table
                ->enum("status", ["pending", "completed", "skipped"])
                ->default("pending");
        });
    }
};
