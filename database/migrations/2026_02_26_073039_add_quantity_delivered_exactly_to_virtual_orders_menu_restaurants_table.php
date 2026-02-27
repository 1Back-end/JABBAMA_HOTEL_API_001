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
        Schema::table('virtual_orders_menu_restaurants', function (Blueprint $table) {
            $table->integer('quantity_delivered_exactly')
                ->after('quantity_delivered')
                ->default(0)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('virtual_orders_menu_restaurants', function (Blueprint $table) {
            $table->dropColumn('quantity_delivered_exactly');
        });
    }
};
