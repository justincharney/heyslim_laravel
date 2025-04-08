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
        // Chats table indexes
        Schema::table("chats", function (Blueprint $table) {
            $table->index("patient_id");
            $table->index("prescription_id");
            $table->index("provider_id");
        });

        // Clinical plan related indexes
        Schema::table("clinical_plans", function (Blueprint $table) {
            $table->index("patient_id");
            $table->index("provider_id");
            $table->index("pharmacist_id");
            $table->index("questionnaire_submission_id");
        });

        Schema::table("clinical_plan_templates", function (Blueprint $table) {
            $table->index("created_by");
            $table->index("team_id");
        });

        // Messages indexes
        Schema::table("messages", function (Blueprint $table) {
            $table->index("chat_id");
            $table->index("user_id");
        });

        // Prescriptions indexes
        Schema::table("prescriptions", function (Blueprint $table) {
            $table->index("patient_id");
            $table->index("prescriber_id");
            $table->index("clinical_plan_id");
        });

        // Prescription templates indexes
        Schema::table("prescription_templates", function (Blueprint $table) {
            $table->index("created_by");
            $table->index("team_id");
        });

        // Questionnaire related indexes
        Schema::table("question_answers", function (Blueprint $table) {
            $table->index("question_id");
        });

        Schema::table("questionnaire_submissions", function (Blueprint $table) {
            $table->index("questionnaire_id");
            $table->index("user_id");
            $table->index("reviewed_by");
        });

        // User related indexes
        Schema::table("users", function (Blueprint $table) {
            $table->index("current_team_id");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove indexes for chats table
        Schema::table("chats", function (Blueprint $table) {
            $table->dropIndex(["patient_id"]);
            $table->dropIndex(["prescription_id"]);
            $table->dropIndex(["provider_id"]);
        });

        // Remove indexes for clinical plans
        Schema::table("clinical_plans", function (Blueprint $table) {
            $table->dropIndex(["patient_id"]);
            $table->dropIndex(["provider_id"]);
            $table->dropIndex(["pharmacist_id"]);
            $table->dropIndex(["questionnaire_submission_id"]);
        });

        Schema::table("clinical_plan_templates", function (Blueprint $table) {
            $table->dropIndex(["created_by"]);
            $table->dropIndex(["team_id"]);
        });

        // Messages indexes
        Schema::table("messages", function (Blueprint $table) {
            $table->dropIndex("chat_id");
            $table->dropIndex("user_id");
        });

        // Prescriptions indexes
        Schema::table("prescriptions", function (Blueprint $table) {
            $table->dropIndex("patient_id");
            $table->dropIndex("prescriber_id");
            $table->dropIndex("clinical_plan_id");
        });

        // Prescription templates indexes
        Schema::table("prescription_templates", function (Blueprint $table) {
            $table->dropIndex("created_by");
            $table->dropIndex("team_id");
        });

        // Questionnaire related indexes
        Schema::table("question_answers", function (Blueprint $table) {
            $table->dropIndex("question_id");
        });

        Schema::table("questionnaire_submissions", function (Blueprint $table) {
            $table->dropIndex("questionnaire_id");
            $table->dropIndex("user_id");
            $table->dropIndex("reviewed_by");
        });

        Schema::table("users", function (Blueprint $table) {
            $table->dropIndex(["current_team_id"]);
        });
    }
};
