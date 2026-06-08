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
        Schema::table('orders_items_complements', function (Blueprint $table) {
            $table->uuid('order_menu_restaurant_uuid')->nullable();
            $table->foreign('order_menu_restaurant_uuid')
                ->references('uuid')
                ->on('orders_menu_restaurants')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders_items_complements', function (Blueprint $table) {
            $table->dropForeign(['order_menu_restaurant_uuid']);
            $table->dropColumn('order_menu_restaurant_uuid');
        });
    }
};
