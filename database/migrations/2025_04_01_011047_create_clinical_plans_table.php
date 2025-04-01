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
        Schema::create("clinical_plans", function (Blueprint $table) {
            $table->id();
            $table
                ->foreignId("patient_id")
                ->constrained("users")
                ->onDelete("cascade");
            $table
                ->foreignId("provider_id")
                ->constrained("users")
                ->onDelete("cascade");
            $table
                ->foreignId("pharmacist_id")
                ->nullable()
                ->constrained("users")
                ->onDelete("set null");
            $table
                ->foreignId("questionnaire_submission_id")
                ->nullable()
                ->constrained("questionnaire_submissions")
                ->onDelete("set null");
            $table->text("condition_treated");
            $table->text("medicines_that_may_be_prescribed");
            $table->text("dose_schedule");
            $table->text("guidelines");
            $table->string("monitoring_frequency");
            $table->text("process_for_reporting_adrs");
            $table->text("patient_allergies");
            $table->dateTime("provider_agreed_at")->nullable();
            $table->dateTime("pharmacist_agreed_at")->nullable();
            $table
                ->enum("status", ["draft", "active", "completed", "abandoned"])
                ->default("draft");
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists("clinical_plans");
    }
};
