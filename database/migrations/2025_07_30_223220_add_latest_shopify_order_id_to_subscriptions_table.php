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
            $table->renameColumn(
                "original_shopify_order_id",
                "latest_shopify_order_id",
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table("subscriptions", function (Blueprint $table) {
            $table->renameColumn(
                "latest_shopify_order_id",
                "original_shopify_order_id",
            );
        });
    }
};
