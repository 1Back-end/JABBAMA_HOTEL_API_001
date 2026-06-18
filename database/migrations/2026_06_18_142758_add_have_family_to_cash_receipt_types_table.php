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
        Schema::table('cash_receipt_types', function (Blueprint $table) {
            $table->boolean('have_family')->default(false)->after('is_linked_to_turnover');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_receipt_types', function (Blueprint $table) {
            $table->dropColumn('have_family');
        });
    }
};
