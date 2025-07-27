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
        Schema::create("soap_charts", function (Blueprint $table) {
            $table->id();
            $table
                ->foreignId("patient_id")
                ->constrained("users")
                ->onDelete("cascade");
            $table
                ->foreignId("provider_id")
                ->constrained("users")
                ->onDelete("cascade");
            $table->string("title")->nullable();
            $table->text("subjective")->nullable();
            $table->text("objective")->nullable();
            $table->text("assessment")->nullable();
            $table->text("plan")->nullable();
            $table
                ->enum("status", ["draft", "completed", "reviewed"])
                ->default("draft");
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists("soap_charts");
    }
};
