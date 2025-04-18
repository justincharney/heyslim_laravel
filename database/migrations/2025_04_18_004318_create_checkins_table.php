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
        Schema::create("check_ins", function (Blueprint $table) {
            $table->id();
            $table
                ->foreignId("subscription_id")
                ->constrained()
                ->onDelete("cascade");
            $table
                ->foreignId("prescription_id")
                ->constrained()
                ->onDelete("cascade");
            $table->foreignId("user_id")->constrained()->onDelete("cascade");
            $table->date("due_date");
            $table->timestamp("completed_at")->nullable();
            $table
                ->enum("status", ["pending", "completed", "skipped"])
                ->default("pending");
            $table->json("questions_and_responses");
            $table->boolean("notification_sent")->default(false);
            $table->foreignId("reviewed_by")->nullable()->constrained("users");
            $table->timestamp("reviewed_at")->nullable();
            $table->text("provider_notes")->nullable();
            $table->timestamps();

            $table->index(["subscription_id", "status"]);
            $table->index(["user_id", "status", "due_date"]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists("check_ins");
    }
};
