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
        Schema::create("chats", function (Blueprint $table) {
            $table->id();
            $table
                ->foreignId("prescription_id")
                ->constrained("prescriptions")
                ->onDelete("cascade");
            $table
                ->foreignId("patient_id")
                ->constrained("users")
                ->onDelete("cascade");
            $table
                ->foreignId("provider_id")
                ->constrained("users")
                ->onDelete("cascade");
            $table->string("title")->default("Provider Chat");
            $table->enum("status", ["active", "closed"])->default("active");
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists("chats");
    }
};
