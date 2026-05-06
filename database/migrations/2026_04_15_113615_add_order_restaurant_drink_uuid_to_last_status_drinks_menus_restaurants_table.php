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

            // 🔥 AJOUT DRINK UUID
            $table->uuid('order_restaurant_drink_uuid')
                ->nullable()
                ->after('uuid');

            // 🔥 FOREIGN KEY
            $table->foreign('order_restaurant_drink_uuid', 'lsdmr_drink_fk')
                ->references('uuid')
                ->on('order_restaurannts_drinks') // ⚠️ vérifie bien le nom exact de ta table
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('last_status_drinks_menus_restaurants', function (Blueprint $table) {
            $table->dropForeign('lsdmr_drink_fk');
            $table->dropColumn('order_restaurant_drink_uuid');
        });
    }
};
