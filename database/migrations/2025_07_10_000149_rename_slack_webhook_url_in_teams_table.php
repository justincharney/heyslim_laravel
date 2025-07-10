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
        Schema::table("teams", function (Blueprint $table) {
            $table->renameColumn(
                "slack_webhook_url",
                "slack_notification_channel"
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table("teams", function (Blueprint $table) {
            $table->renameColumn(
                "slack_notification_channel",
                "slack_webhook_url"
            );
        });
    }
};
