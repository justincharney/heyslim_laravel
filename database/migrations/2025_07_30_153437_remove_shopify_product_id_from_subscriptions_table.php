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
            if (Schema::hasColumn("subscriptions", "shopify_product_id")) {
                $table->dropColumn("shopify_product_id");
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table("subscriptions", function (Blueprint $table) {
            if (!Schema::hasColumn("subscriptions", "shopify_product_id")) {
                $table->string("shopify_product_id")->nullable();
            }
        });
    }
};
