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
        Schema::table('statistics_orders_status_menus_restaurants', function (Blueprint $table) {
            $table->uuid('order_menu_restaurant_uuid')->after('order_menu_restaurant_item_uuid');

            $table->foreign('order_menu_restaurant_uuid', 'fk_stats_order_menu')
                ->references('uuid')
                ->on('orders_menu_restaurants')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('statistics_orders_status_menus_restaurants', function (Blueprint $table) {
            $table->dropForeign('fk_stats_order_menu');
            $table->dropColumn('order_menu_restaurant_uuid');
        });
    }
};
