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
        Schema::table('menu_orders_items', function (Blueprint $table) {
            $table->uuid('menus_restaurant_uuid')->nullable()->after('menu_order_uuid');

            // Définir la clé étrangère
            $table->foreign('menus_restaurant_uuid')
                ->references('uuid')
                ->on('menus_restaurants')
                ->onDelete('set null'); // Si le menu est supprimé, mettre à null
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('menu_orders_items', function (Blueprint $table) {
            $table->dropForeign(['menus_restaurant_uuid']);
            $table->dropColumn('menus_restaurant_uuid');
        });
    }
};
