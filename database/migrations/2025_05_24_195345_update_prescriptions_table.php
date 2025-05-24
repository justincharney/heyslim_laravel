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
            // Add new status with pending_signature and pending_payment options
            $table
                ->enum("status", [
                    "pending_payment",
                    "pending_signature",
                    "active",
                    "completed",
                    "cancelled",
                ])
                ->default("pending_payment"); // Set pending_payment as the default
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table("prescriptions", function (Blueprint $table) {
            // Drop the current status enum including the new option
            $table->dropColumn("status");
            // Revert to the original status enum
            $table
                ->enum("status", [
                    "active",
                    "completed",
                    "cancelled",
                    "pending_signature",
                ])
                ->default("pending_signature");
        });
    }
};
