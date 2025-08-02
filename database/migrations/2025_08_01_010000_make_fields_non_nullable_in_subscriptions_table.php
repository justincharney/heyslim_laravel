<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        // Important: Before running this migration, ensure all existing records
        // in the `subscriptions` table have valid, non-null values for the
        // columns being changed. You may need to run a data cleanup script first.
        Schema::table("subscriptions", function (Blueprint $table) {
            $table->string("chargebee_customer_id")->nullable(false)->change();
            $table
                ->string("chargebee_item_price_id")
                ->nullable(false)
                ->change();
            $table->string("status")->nullable(false)->change();
            $table
                ->foreignId("questionnaire_submission_id")
                ->nullable(false)
                ->change();
            $table->foreignId("user_id")->nullable(false)->change();
            $table->date("next_charge_scheduled_at")->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table("subscriptions", function (Blueprint $table) {
            $table->string("chargebee_customer_id")->nullable()->change();
            $table->string("chargebee_item_price_id")->nullable()->change();
            $table->string("status")->nullable()->change();
            $table
                ->foreignId("questionnaire_submission_id")
                ->nullable()
                ->change();
            $table->foreignId("user_id")->nullable()->change();
            $table->date("next_charge_scheduled_at")->nullable()->change();
        });
    }
};
