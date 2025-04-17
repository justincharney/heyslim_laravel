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
        Schema::create("processed_recurring_orders", function (
            Blueprint $table
        ) {
            $table->id();
            $table->string("shopify_order_id");
            $table
                ->foreignId("prescription_id")
                ->nullable()
                ->constrained()
                ->onDelete("cascade");
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists("processed_recurring_orders");
    }
};
