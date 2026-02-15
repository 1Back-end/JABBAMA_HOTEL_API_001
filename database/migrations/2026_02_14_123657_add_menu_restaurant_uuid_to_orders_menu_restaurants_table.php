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
        Schema::table('orders_menu_restaurants', function (Blueprint $table) {
            $table->uuid('menu_restaurant_uuid')
                ->after('uuid');

            $table->foreign('menu_restaurant_uuid')
                ->references('uuid')
                ->on('menus_restaurants')
                ->cascadeOnDelete();

            $table->integer('quantity')->default(0)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders_menu_restaurants', function (Blueprint $table) {
            $table->dropForeign(['menu_restaurant_uuid']);
            $table->dropColumn('menu_restaurant_uuid');
            $table->dropColumn('quantity');
        });
    }
};
