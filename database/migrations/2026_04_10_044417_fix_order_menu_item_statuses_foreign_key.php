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
        // ✅ 1. Ajouter colonne si elle n'existe pas
        if (!Schema::hasColumn('order_menu_item_statuses', 'order_menu_restaurant_uuid')) {
            Schema::table('order_menu_item_statuses', function (Blueprint $table) {
                $table->uuid('order_menu_restaurant_uuid')
                    ->nullable()
                    ->after('order_menu_restaurant_item_uuid');
            });
        }

        // ✅ 2. Nettoyer données invalides
        DB::statement("
            DELETE FROM order_menu_item_statuses
            WHERE order_menu_restaurant_uuid IS NOT NULL
            AND order_menu_restaurant_uuid NOT IN (
                SELECT uuid FROM orders_menu_restaurants
            )
        ");

        // ✅ 3. Supprimer ancienne FK si elle existe (safe)
        try {
            Schema::table('order_menu_item_statuses', function (Blueprint $table) {
                $table->dropForeign(['order_menu_restaurant_uuid']);
            });
        } catch (\Exception $e) {
            // ignore si elle n'existe pas
        }

        // ✅ 4. Ajouter nouvelle FK
        Schema::table('order_menu_item_statuses', function (Blueprint $table) {
            $table->foreign('order_menu_restaurant_uuid', 'fk_order_item_status_menu')
                ->references('uuid')
                ->on('orders_menu_restaurants')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('order_menu_item_statuses', function (Blueprint $table) {
            try {
                $table->dropForeign('fk_order_item_status_menu');
            } catch (\Exception $e) {}
        });
    }
};
