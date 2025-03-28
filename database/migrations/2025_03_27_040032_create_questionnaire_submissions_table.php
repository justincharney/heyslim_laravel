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
        Schema::create("questionnaire_submissions", function (
            Blueprint $table
        ) {
            $table->id();
            $table
                ->foreignId("questionnaire_id")
                ->constrained()
                ->onDelete("cascade");
            $table->foreignId("user_id")->constrained()->onDelete("cascade");
            $table->timestamp("submitted_at")->useCurrent();
            $table
                ->enum("status", ["draft", "submitted", "approved", "rejected"])
                ->default("draft");
            $table->text("review_notes")->nullable();
            $table->foreignId("reviewed_by")->nullable();
            $table
                ->foreign("reviewed_by")
                ->references("id")
                ->on("users")
                ->onDelete("set null");
            $table->timestamp("reviewed_at")->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists("questionnaire_submissions");
    }
};
