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
        Schema::table("clinical_plans", function (Blueprint $table) {
            $table->dropForeign(["pharmacist_id"]);
            $table->dropIndex(["pharmacist_id"]);
            $table->dropColumn("pharmacist_id");

            $table->dropColumn("pharmacist_agreed_at");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table("clinical_plans", function (Blueprint $table) {
            $table
                ->foreignId("pharmacist_id")
                ->nullable()
                ->constrained("users")
                ->onDelete("set null");
            $table->index("pharmacist_id");
            $table->dateTime("pharmacist_agreed_at")->nullable();
        });
    }
};
