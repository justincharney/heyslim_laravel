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
        Schema::table("prescription_templates", function (Blueprint $table) {
            $table
                ->json("dose_schedule")
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
        Schema::table("prescription_templates", function (Blueprint $table) {
            $table->dropColumn("dose_schedule");
        });
    }
};
