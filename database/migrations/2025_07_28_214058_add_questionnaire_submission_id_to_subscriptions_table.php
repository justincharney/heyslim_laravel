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
        Schema::table("subscriptions", function (Blueprint $table) {
            $table
                ->foreignId("questionnaire_submission_id")
                ->nullable()
                ->constrained("questionnaire_submissions")
                ->onDelete("cascade");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table("subscriptions", function (Blueprint $table) {
            $table->dropForeign(["questionnaire_submission_id"]);
            $table->dropColumn("questionnaire_submission_id");
        });
    }
};
