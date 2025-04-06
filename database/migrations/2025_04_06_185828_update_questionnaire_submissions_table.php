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
        Schema::table("questionnaire_submissions", function (Blueprint $table) {
            $table->dropColumn("status");
            $table
                ->enum("status", [
                    "draft",
                    "pending_payment",
                    "submitted",
                    "approved",
                    "rejected",
                ])
                ->default("draft");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table("questionnaire_submissions", function (Blueprint $table) {
            $table->dropColumn("status");
            $table
                ->enum("status", ["draft", "submitted", "approved", "rejected"])
                ->default("draft");
        });
    }
};
