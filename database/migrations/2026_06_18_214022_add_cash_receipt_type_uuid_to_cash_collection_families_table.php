<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cash_collection_families', function (Blueprint $table) {
            $table->uuid('cash_receipt_type_uuid')
                ->nullable()
                ->after('uuid');

            $table->foreign('cash_receipt_type_uuid')
                ->references('uuid')
                ->on('cash_receipt_types')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_collection_families', function (Blueprint $table) {
            $table->dropForeign(['cash_receipt_type_uuid']);
            $table->dropColumn('cash_receipt_type_uuid');
        });
    }
};
