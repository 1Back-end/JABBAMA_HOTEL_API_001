<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('orders_menu_restaurants')
            ->where('status', 'paid')
            ->update(['regulation_status' => 'paid']);

        DB::unprepared('DROP TRIGGER IF EXISTS before_insert_orders_regulation_status');
        DB::unprepared('DROP TRIGGER IF EXISTS before_update_orders_regulation_status');

        DB::unprepared('
            CREATE TRIGGER before_insert_orders_regulation_status
            BEFORE INSERT ON orders_menu_restaurants
            FOR EACH ROW
            BEGIN
                IF NEW.status = \'paid\' THEN
                    SET NEW.regulation_status = \'paid\';
                END IF;
            END
        ');

        DB::unprepared('
            CREATE TRIGGER before_update_orders_regulation_status
            BEFORE UPDATE ON orders_menu_restaurants
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
        DB::unprepared('DROP TRIGGER IF EXISTS before_insert_orders_regulation_status');
        DB::unprepared('DROP TRIGGER IF EXISTS before_update_orders_regulation_status');

        DB::table('orders_menu_restaurants')
            ->where('status', 'paid')
            ->update(['regulation_status' => 'not_paid']);
    }
};
