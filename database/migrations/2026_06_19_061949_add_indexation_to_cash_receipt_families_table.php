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
            $table->string('indexation')
                ->default('restaurant')
                ->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_receipt_families', function (Blueprint $table) {
            $table->dropColumn('indexation');
        });
    }
};
