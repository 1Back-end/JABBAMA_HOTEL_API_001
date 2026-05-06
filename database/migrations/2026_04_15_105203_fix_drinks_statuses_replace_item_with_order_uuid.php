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

            // 🔥 1. AJOUT DU BON CHAMP (ORDER)
            $table->uuid('order_menu_restaurant_uuid')
                ->nullable()
                ->after('uuid');

            $table->foreign('order_menu_restaurant_uuid', 'omsfd_order_fk')
                ->references('uuid')
                ->on('orders_menu_restaurants')
                ->cascadeOnDelete();

            // 🔥 2. RENDRE ANCIEN CHAMP NULLABLE (transition safe)
            $table->uuid('order_menu_restaurant_item_uuid')
                ->nullable()
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders_menu_item_statuses_for_drinks', function (Blueprint $table) {

            $table->dropForeign('omsfd_order_fk');
            $table->dropColumn('order_menu_restaurant_uuid');

            $table->uuid('order_menu_restaurant_item_uuid')
                ->nullable(false)
                ->change();
        });
    }
};
