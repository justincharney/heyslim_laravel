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
        Schema::create("clinical_plan_templates", function (Blueprint $table) {
            $table->id();
            $table->string("name");
            $table->text("description")->nullable();
            $table->text("condition_treated");
            $table->text("medicines_that_may_be_prescribed");
            $table->text("dose_schedule");
            $table->text("guidelines");
            $table->string("monitoring_frequency");
            $table->text("process_for_reporting_adrs");
            $table
                ->foreignId("created_by")
                ->constrained("users")
                ->onDelete("cascade");
            $table->boolean("is_global")->default(false); // For system-wide templates
            $table
                ->foreignId("team_id")
                ->nullable()
                ->constrained("teams")
                ->onDelete("cascade");
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists("clinical_plan_templates");
    }
};
