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
        Schema::table('supply_items', function (Blueprint $table) {
            $table->decimal('sell_price', 15, 2)->nullable()->after('quantity_supplied');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supply_items', function (Blueprint $table) {
            $table->dropColumn('sell_price');
        });
    }
};
