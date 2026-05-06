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
        Schema::table('notifications', function (Blueprint $table) {
            if (!Schema::hasColumn('notifications', 'order_menu_restaurant_item_uuid')) {
                $table->uuid('order_menu_restaurant_item_uuid')->nullable()->after('data');
            }

            // 2. On ajoute la COMMANDE UUID
            $table->uuid('order_menu_restaurant_uuid')
                ->nullable()
                ->after('order_menu_restaurant_item_uuid');

            // 3. La clé étrangère
            $table->foreign('order_menu_restaurant_uuid', 'fk_notif_order_menu')
                ->references('uuid')
                ->on('orders_menu_restaurants')
                ->cascadeOnDelete();

            $table->string('status')->nullable()->after('order_menu_restaurant_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropForeign('fk_notif_order_menu');
            $table->dropColumn(['order_menu_restaurant_uuid', 'status']);
        });
    }
};
