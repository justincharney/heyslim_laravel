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
        Schema::table("users", function (Blueprint $table) {
            $table->text("address")->nullable();
            $table->date("date_of_birth")->nullable();
            $table->boolean("profile_completed")->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table("users", function (Blueprint $table) {
            $table->dropColumn("address");
            $table->dropColumn("date_of_birth");
            $table->dropColumn("profile_completed");
        });
    }
};
