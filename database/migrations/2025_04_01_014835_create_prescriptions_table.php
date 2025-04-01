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
        Schema::create("prescriptions", function (Blueprint $table) {
            $table->id();
            $table
                ->foreignId("patient_id")
                ->constrained("users")
                ->onDelete("cascade");
            $table
                ->foreignId("prescriber_id")
                ->constrained("users")
                ->onDelete("cascade");
            $table
                ->foreignId("clinical_plan_id")
                ->nullable()
                ->constrained("clinical_plans")
                ->onDelete("set null");
            $table->string("medication_name");
            $table->string("dose");
            $table->string("schedule");
            $table->integer("refills");
            $table->text("directions")->nullable();
            $table
                ->enum("status", ["active", "completed", "cancelled"])
                ->default("active");
            $table->date("start_date");
            $table->date("end_date")->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists("prescriptions");
    }
};
