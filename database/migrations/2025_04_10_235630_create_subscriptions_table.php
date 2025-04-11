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
        Schema::create("subscriptions", function (Blueprint $table) {
            $table->id();
            $table->string("recharge_subscription_id")->nullable();
            $table->string("recharge_customer_id")->nullable();
            $table->string("shopify_product_id")->nullable();
            $table->string("product_name");
            $table
                ->enum("status", ["active", "paused", "cancelled"])
                ->default("active");
            $table
                ->foreignId("questionnaire_submission_id")
                ->constrained()
                ->onDelete("set null");
            $table
                ->foreignId("prescription_id")
                ->nullable()
                ->constrained()
                ->onDelete("set null");
            $table->foreignId("user_id")->constrained()->onDelete("cascade");
            $table->timestamps();

            // Indexes for quick lookup
            $table->index("recharge_subscription_id");
            $table->index("recharge_customer_id");
            $table->index("user_id");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists("subscriptions");
    }
};
