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
            $table->unsignedBigInteger("replaces_prescription_id")->nullable();
            $table
                ->foreign("replaces_prescription_id")
                ->references("id")
                ->on("prescriptions")
                ->onDelete("set null");
            $table
                ->unsignedBigInteger("replaced_by_prescription_id")
                ->nullable();
            $table
                ->foreign("replaced_by_prescription_id")
                ->references("id")
                ->on("prescriptions")
                ->onDelete("set null");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table("prescriptions", function (Blueprint $table) {
            $table->dropForeign(["replaces_prescription_id"]);
            $table->dropColumn("replaces_prescription_id");
            $table->dropForeign(["replaced_by_prescription_id"]);
            $table->dropColumn("replaced_by_prescription_id");
        });
    }
};
