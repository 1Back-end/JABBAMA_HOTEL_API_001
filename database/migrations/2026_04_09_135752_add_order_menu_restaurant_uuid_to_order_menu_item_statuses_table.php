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
        Schema::table('order_menu_item_statuses', function (Blueprint $table) {
            $table->uuid('order_menu_restaurant_uuid')->after('order_menu_restaurant_item_uuid');

            // Foreign key avec nom court
            $table->foreign('order_menu_restaurant_uuid', 'fk_order_item_status_menu')
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
        Schema::table('order_menu_item_statuses', function (Blueprint $table) {
            $table->dropForeign('fk_order_item_status_menu');
            $table->dropColumn('order_menu_restaurant_uuid');
        });
    }
};
