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
            if (!Schema::hasColumn("users", "workos_id")) {
                $table->string("workos_id")->nullable()->after("email");
            }
            if (!Schema::hasColumn("users", "avatar")) {
                $table->text("avatar")->nullable()->after("email_verified_at");
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table("users", function (Blueprint $table) {
            if (Schema::hasColumn("users", "workos_id")) {
                $table->dropColumn("workos_id");
            }

            if (Schema::hasColumn("users", "avatar")) {
                $table->dropColumn("avatar");
            }
        });
    }
};
