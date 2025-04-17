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
        Schema::table("prescriptions", function (Blueprint $table) {
            $table->renameColumn(
                "yousign_procedure_id",
                "yousign_signature_request_id"
            );
            $table->string("yousign_document_id")->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table("prescriptions", function (Blueprint $table) {
            $table->dropColumn("yousign_document_id");
            $table->renameColumn(
                "yousign_signature_request_id",
                "yousign_procedure_id"
            );
        });
    }
};
