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
        Schema::create('last_status_items_menus_restaurants', function (Blueprint $table) {

            $table->uuid('uuid')->primary();

            $table->uuid('order_menu_restaurant_item_uuid');
            $table->uuid('order_menu_restaurant_uuid');

            $table->string('type')->default('menu');
            $table->string('last_status')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // 🔗 foreign keys (OK)
            $table->foreign('order_menu_restaurant_item_uuid', 'fk_lsimr_item')
                ->references('uuid')
                ->on('orders_menu_restaurant_items')
                ->cascadeOnDelete();

            $table->foreign('order_menu_restaurant_uuid', 'fk_lsimr_order')
                ->references('uuid')
                ->on('orders_menu_restaurants')
                ->cascadeOnDelete();

            // ⚡ INDEX (RENOMMÉS COURTS)
            $table->index('order_menu_restaurant_item_uuid', 'idx_lsimr_item');
            $table->index('order_menu_restaurant_uuid', 'idx_lsimr_order');
            $table->index('type', 'idx_lsimr_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('last_status_items_menus_restaurants');
    }
};
