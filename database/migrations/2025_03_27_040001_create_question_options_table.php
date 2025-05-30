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
        Schema::create("question_options", function (Blueprint $table) {
            $table->id();
            $table
                ->foreignId("question_id")
                ->constrained("questions")
                ->onDelete("cascade");
            $table->unsignedInteger("option_number");
            $table->string("option_text");
            $table->timestamps();

            $table->unique(["question_id", "option_number"]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists("question_options");
    }
};
