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
        Schema::table("prescriptions", function (Blueprint $table) {
            // Drop the current status enum to replace with new options
            $table->dropColumn("status");
            // Add new status with replaced option
            $table
                ->enum("status", [
                    "pending_signature",
                    "active",
                    "completed",
                    "cancelled",
                    "replaced",
                ])
                ->default("pending_signature");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table("prescriptions", function (Blueprint $table) {
            // Drop the current status enum to replace with old options
            $table->dropColumn("status");
            $table
                ->enum("status", [
                    "pending_signature",
                    "active",
                    "completed",
                    "cancelled",
                ])
                ->default("pending_signature");
        });
    }
};
