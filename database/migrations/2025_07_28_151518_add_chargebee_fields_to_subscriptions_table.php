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
            $table->string("chargebee_subscription_id")->nullable();
            $table->index("chargebee_subscription_id");
            $table->string("chargebee_customer_id")->nullable();
            $table->index("chargebee_customer_id");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table("subscriptions", function (Blueprint $table) {
            $table->dropColumn("chargebee_subscription_id");
            $table->dropColumn("chargebee_customer_id");
            $table->dropIndex("chargebee_subscription_id");
            $table->dropIndex("chargebee_customer_id");
        });
    }
};
