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
        Schema::create("questions", function (Blueprint $table) {
            $table->id();
            $table
                ->foreignId("questionnaire_id")
                ->constrained()
                ->onDelete("cascade");
            $table->unsignedInteger("question_number");
            $table->text("question_text");
            $table->string("label")->nullable();
            $table->text("description")->nullable();
            $table
                ->enum("question_type", [
                    "text",
                    "textarea",
                    "checkbox",
                    "multi-select",
                    "select",
                    "date",
                    "yes_no",
                    "tel",
                    "email",
                    "number",
                ])
                ->default("text");
            $table->json("calculated")->nullable();
            $table->json("validation")->nullable();
            $table->boolean("is_required")->default(true);
            $table->timestamps();

            // candidate key
            $table->unique(["questionnaire_id", "question_number"]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists("questions");
    }
};
