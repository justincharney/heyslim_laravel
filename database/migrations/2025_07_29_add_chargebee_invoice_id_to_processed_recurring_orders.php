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
        Schema::table("processed_recurring_orders", function (
            Blueprint $table,
        ) {
            $table->dropColumn("shopify_order_id");
            $table->string("chargebee_invoice_id")->nullable();
            $table->index("chargebee_invoice_id");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table("processed_recurring_orders", function (
            Blueprint $table,
        ) {
            $table->string("shopify_order_id")->nullable();
            $table->dropIndex(["chargebee_invoice_id"]);
            $table->dropColumn("chargebee_invoice_id");
        });
    }
};
