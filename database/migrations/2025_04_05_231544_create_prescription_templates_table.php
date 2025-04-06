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
        Schema::create("prescription_templates", function (Blueprint $table) {
            $table->id();
            $table->string("name");
            $table->text("description")->nullable();
            $table->string("medication_name");
            $table->text("dose");
            $table->text("schedule");
            $table->integer("refills")->default(0);
            $table->text("directions")->nullable();
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
        Schema::dropIfExists("prescription_templates");
    }
};
