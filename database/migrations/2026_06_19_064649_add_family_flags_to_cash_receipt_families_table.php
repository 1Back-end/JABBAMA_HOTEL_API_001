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
            $table->boolean('is_family')
                ->default(true)
                ->after('indexation');

            $table->boolean('is_sub_family')
                ->default(false)
                ->after('is_family');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_receipt_families', function (Blueprint $table) {
            $table->dropColumn(['is_family', 'is_sub_family']);
        });
    }
};
