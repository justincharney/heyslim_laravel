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
            $table->string("original_shopify_order_id")->nullable();
            $table->index("original_shopify_order_id");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table("subscriptions", function (Blueprint $table) {
            $table->dropIndex(["original_shopify_order_id"]);
            $table->dropColumn("original_shopify_order_id");
        });
    }
};
