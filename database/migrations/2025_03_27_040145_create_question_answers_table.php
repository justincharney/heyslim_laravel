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
        Schema::create("question_answers", function (Blueprint $table) {
            $table->id();
            $table
                ->foreignId("submission_id")
                ->constrained("questionnaire_submissions")
                ->onDelete("cascade");
            $table
                ->foreignId("question_id")
                ->constrained("questions")
                ->onDelete("cascade");
            $table->text("answer_text")->nullable();
            $table->timestamps();

            $table->unique(["submission_id", "question_id"]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists("question_answers");
    }
};
