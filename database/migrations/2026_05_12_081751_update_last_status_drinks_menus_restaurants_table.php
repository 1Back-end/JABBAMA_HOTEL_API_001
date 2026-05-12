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
        Schema::table('last_status_drinks_menus_restaurants', function (Blueprint $table) {
            $table->dropForeign('lsdmr_product_fk');
            $table->dropColumn('product_uuid');
            $table->uuid('drink_restaurant_uuid')->after('order_restaurant_drink_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('last_status_drinks_menus_restaurants', function (Blueprint $table) {
            $table->dropColumn('drink_restaurant_uuid');
            $table->uuid('product_uuid');
            $table->foreign('product_uuid', 'lsdmr_product_fk')
                ->references('uuid')
                ->on('produits')
                ->onDelete('cascade');
        });
    }
};
