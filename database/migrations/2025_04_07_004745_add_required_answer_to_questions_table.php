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
        Schema::table("questions", function (Blueprint $table) {
            $table->string("required_answer")->nullable()->after("is_required");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table("questions", function (Blueprint $table) {
            $table->dropColumn("required_answer");
        });
    }
};
