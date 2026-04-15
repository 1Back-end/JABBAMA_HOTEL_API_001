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
        Schema::table('order_menu_restaurant_defective_drinks', function (Blueprint $table) {

            // 🔥 AJOUT ORDER UUID (si pas encore présent)
            if (!Schema::hasColumn('order_menu_restaurant_defective_drinks', 'order_menu_restaurant_uuid')) {

                $table->uuid('order_menu_restaurant_uuid')
                    ->nullable()
                    ->after('uuid');

                $table->foreign('order_menu_restaurant_uuid', 'omrdd_order_fk')
                    ->references('uuid')
                    ->on('orders_menu_restaurants')
                    ->cascadeOnDelete();
            }

            // 🔥 rendre item_uuid obsolete (phase safe)
            if (Schema::hasColumn('order_menu_restaurant_defective_drinks', 'order_menu_restaurant_item_uuid')) {
                $table->uuid('order_menu_restaurant_item_uuid')->nullable()->change();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_menu_restaurant_defective_drinks', function (Blueprint $table) {

            $table->dropForeign('omrdd_order_fk');
            $table->dropColumn('order_menu_restaurant_uuid');

            $table->uuid('order_menu_restaurant_item_uuid')->nullable(false)->change();
        });
    }
};
