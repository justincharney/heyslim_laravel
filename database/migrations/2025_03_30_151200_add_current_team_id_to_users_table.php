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
            $table
                ->unsignedBigInteger("current_team_id")
                ->nullable()
                ->after("id");
            $table
                ->foreign("current_team_id")
                ->references("id")
                ->on("teams")
                ->onDelete("set null");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table("users", function (Blueprint $table) {
            $table->dropForeign(["current_team_id"]);
            $table->dropColumn("current_team_id");
        });
    }
};
