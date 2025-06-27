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
        Schema::create("weight_logs", function (Blueprint $table) {
            $table->id();
            $table->foreignId("user_id")->constrained()->onDelete("cascade");
            $table->decimal("weight", 5, 2);
            $table->enum("unit", ["kg", "lbs"])->default("kg");
            $table->date("log_date");
            $table->timestamps();

            // Ensure one entry per user per day
            $table->unique(["user_id", "log_date"]);

            // Indexes for performance
            $table->index(["user_id", "log_date"]);
            $table->index("log_date");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists("weight_logs");
    }
};
