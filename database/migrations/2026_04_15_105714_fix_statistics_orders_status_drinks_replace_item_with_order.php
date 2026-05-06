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
        Schema::table('statistics_orders_status_drinks', function (Blueprint $table) {

            // 🔥 1. AJOUT ORDER UUID (si pas encore présent)
            if (!Schema::hasColumn('statistics_orders_status_drinks', 'order_menu_restaurant_uuid')) {

                $table->uuid('order_menu_restaurant_uuid')
                    ->nullable()
                    ->after('uuid');

                $table->foreign('order_menu_restaurant_uuid', 'sosd_order_fk')
                    ->references('uuid')
                    ->on('order_menu_restaurants')
                    ->cascadeOnDelete();
            }

            // 🔥 2. SUPPRESSION / OBSOLÈTE ITEM UUID
            if (Schema::hasColumn('statistics_orders_status_drinks', 'order_menu_restaurant_item_uuid')) {
                $table->dropColumn('order_menu_restaurant_item_uuid');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('statistics_orders_status_drinks', function (Blueprint $table) {

            $table->dropForeign('sosd_order_fk');
            $table->dropColumn('order_menu_restaurant_uuid');

            $table->uuid('order_menu_restaurant_item_uuid')->nullable();
        });
    }
};
