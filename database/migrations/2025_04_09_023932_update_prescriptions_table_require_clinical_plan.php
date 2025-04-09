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
            // Drop the existing foreign key constraint
            $table->dropForeign(["clinical_plan_id"]);
            // Make the column non-nullable
            $table->foreignId("clinical_plan_id")->nullable(false)->change();

            // Add the new foreign key constraint with cascade delete
            $table
                ->foreign("clinical_plan_id")
                ->references("id")
                ->on("clinical_plans")
                ->onDelete("cascade");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table("prescriptions", function (Blueprint $table) {
            // Drop the existing foreign key constraint
            $table->dropForeign(["clinical_plan_id"]);

            // Revert back to nullable
            $table->foreignId("clinical_plan_id")->nullable()->change();

            // Add back the original foreign key constraint
            $table
                ->foreign("clinical_plan_id")
                ->references("id")
                ->on("clinical_plans")
                ->onDelete("set null");
        });
    }
};
