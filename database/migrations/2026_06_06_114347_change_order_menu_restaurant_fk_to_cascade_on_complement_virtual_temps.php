<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complement_virtual_temps', function (Blueprint $table) {

            $table->dropForeign(
                'complement_virtual_temps_order_menu_restaurant_uuid_foreign'
            );

            $table->foreign(
                'order_menu_restaurant_uuid',
                'cvt_order_menu_restaurant_fk'
            )
                ->references('uuid')
                ->on('orders_menu_restaurants')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('complement_virtual_temps', function (Blueprint $table) {

            $table->dropForeign('cvt_order_menu_restaurant_fk');

            $table->foreign(
                'order_menu_restaurant_uuid',
                'complement_virtual_temps_order_menu_restaurant_uuid_foreign'
            )
                ->references('uuid')
                ->on('orders_menu_restaurants')
                ->nullOnDelete();
        });
    }
};
