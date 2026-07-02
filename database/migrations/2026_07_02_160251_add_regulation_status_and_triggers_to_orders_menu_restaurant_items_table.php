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

        if (!Schema::hasColumn('orders_menu_restaurant_items', 'regulation_status')) {
            Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {
                $table->string('regulation_status')->default('not_paid');
            });
        }


        DB::table('orders_menu_restaurant_items')
            ->where('status', 'paid')
            ->update(['regulation_status' => 'paid']);


        DB::unprepared('DROP TRIGGER IF EXISTS before_insert_items_regulation_status');
        DB::unprepared('DROP TRIGGER IF EXISTS before_update_items_regulation_status');

        DB::unprepared('
            CREATE TRIGGER before_insert_items_regulation_status
            BEFORE INSERT ON orders_menu_restaurant_items
            FOR EACH ROW
            BEGIN
                IF NEW.status = \'paid\' THEN
                    SET NEW.regulation_status = \'paid\';
                END IF;
            END
        ');


        DB::unprepared('
            CREATE TRIGGER before_update_items_regulation_status
            BEFORE UPDATE ON orders_menu_restaurant_items
            FOR EACH ROW
            BEGIN
                IF NEW.status = \'paid\' THEN
                    SET NEW.regulation_status = \'paid\';
                END IF;
            END
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS before_insert_items_regulation_status');
        DB::unprepared('DROP TRIGGER IF EXISTS before_update_items_regulation_status');

        if (Schema::hasColumn('orders_menu_restaurant_items', 'regulation_status')) {
            Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {
                $table->dropColumn('regulation_status');
            });
        }
    }
};
