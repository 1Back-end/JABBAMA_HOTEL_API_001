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
        if (Schema::hasColumn('order_restaurannts_drinks', 'total_price')) {
            Schema::table('order_restaurannts_drinks', function (Blueprint $table) {
                $table->dropColumn('total_price');
            });
        }

        Schema::table('order_restaurannts_drinks', function (Blueprint $table) {
            $table->integer('total_price')
                ->storedAs('unit_price * quantity_exactly')
                ->after('unit_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('order_restaurannts_drinks', 'total_price')) {
            Schema::table('order_restaurannts_drinks', function (Blueprint $table) {
                $table->dropColumn('total_price');
            });
        }

        Schema::table('order_restaurannts_drinks', function (Blueprint $table) {
            $table->integer('total_price')->nullable()->after('unit_price');
        });
    }
};
