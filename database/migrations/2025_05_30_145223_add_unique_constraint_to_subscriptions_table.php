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
        Schema::table("subscriptions", function (Blueprint $table) {
            $table->unique("recharge_subscription_id");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table("subscriptions", function (Blueprint $table) {
            $table->dropUnique("recharge_subscription_id");
        });
    }
};
