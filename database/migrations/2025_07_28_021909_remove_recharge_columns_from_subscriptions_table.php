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
            $table->dropColumn([
                "recharge_subscription_id",
                "recharge_customer_id",
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table("subscriptions", function (Blueprint $table) {
            $table->string("recharge_subscription_id")->nullable()->after("id");
            $table
                ->string("recharge_customer_id")
                ->nullable()
                ->after("recharge_subscription_id");
        });
    }
};
