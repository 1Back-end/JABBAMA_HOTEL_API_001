<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('orders_menu_restaurant_items', 'total_price')) {
            Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {
                $table->dropColumn('total_price');
            });
        }

        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {
            $table->integer('total_price')->nullable()->after('unit_price');
        });

        DB::unprepared('DROP TRIGGER IF EXISTS before_insert_orders_menu_total_price');
        DB::unprepared('DROP TRIGGER IF EXISTS before_update_orders_menu_total_price');

        DB::unprepared('
            CREATE TRIGGER before_insert_orders_menu_total_price
            BEFORE INSERT ON orders_menu_restaurant_items
            FOR EACH ROW
            BEGIN
                IF NEW.total_price IS NULL THEN
                    SET NEW.total_price = NEW.unit_price * NEW.quantity_exactly;
                END IF;
            END
        ');

        DB::unprepared('
            CREATE TRIGGER before_update_orders_menu_total_price
            BEFORE UPDATE ON orders_menu_restaurant_items
            FOR EACH ROW
            BEGIN
                IF NEW.unit_price <> OLD.unit_price OR NEW.quantity_exactly <> OLD.quantity_exactly THEN
                    SET NEW.total_price = NEW.unit_price * NEW.quantity_exactly;
                END IF;
            END
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS before_insert_orders_menu_total_price');
        DB::unprepared('DROP TRIGGER IF EXISTS before_update_orders_menu_total_price');

        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {
            $table->dropColumn('total_price');
        });

        // On remet la colonne comme elle était à l'origine (virtuelle stockée)
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {
            $table->integer('total_price')
                ->storedAs('unit_price * quantity_exactly')
                ->after('unit_price');
        });
    }
};
