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
        Schema::table('cash_receipt_families', function (Blueprint $table) {
            $table->uuid('parent_uuid')->nullable()->after('cash_receipt_type_uuid');

            $table->integer('level')
                ->default(1)
                ->after('parent_uuid');


            $table->foreign('parent_uuid')
                ->references('uuid')
                ->on('cash_receipt_families')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_receipt_families', function (Blueprint $table) {
            $table->dropForeign(['parent_uuid']);

            $table->dropColumn([
                'parent_uuid',
                'level',
                'is_active'
            ]);
        });
    }
};
