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
        Schema::table('orders_menu_item_statuses_for_drinks', function (Blueprint $table) {
            Schema::table('orders_menu_item_statuses_for_drinks', function (Blueprint $table) {

                // 🔥 AJOUT COLONNE DRINK
                $table->uuid('order_restaurant_drink_uuid')
                    ->nullable()
                    ->after('uuid');

                // 🔥 FOREIGN KEY
                $table->foreign('order_restaurant_drink_uuid', 'omsfd_drink_fk')
                    ->references('uuid')
                    ->on('order_restaurannts_drinks') // ⚠️ attention à ton nom de table (typo)
                    ->cascadeOnDelete();
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders_menu_item_statuses_for_drinks', function (Blueprint $table) {
            $table->dropForeign('omsfd_drink_fk');
            $table->dropColumn('order_restaurant_drink_uuid');
        });
    }
};
