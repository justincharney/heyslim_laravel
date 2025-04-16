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
            // Add new status with pending_signature option
            $table
                ->enum("status", [
                    "pending_signature",
                    "active",
                    "completed",
                    "cancelled",
                ])
                ->default("pending_signature");

            // Add fields for tracking signatures
            $table->string("yousign_procedure_id")->nullable();
            $table->timestamp("signed_at")->nullable();

            // Add field for dose information
            $table
                ->json("dose_schedule")
                ->nullable()
                ->comment(
                    "JSON structure containing the complete dosing schedule"
                );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table("prescriptions", function (Blueprint $table) {
            $table->dropColumn("status");
            $table
                ->enum("status", ["active", "completed", "cancelled"])
                ->default("active");
            $table->dropColumn("yousign_procedure_id");
            $table->dropColumn("signed_at");
            $table->dropColumn("dose_schedule");
        });
    }
};
