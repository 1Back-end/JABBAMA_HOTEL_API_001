<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('orders_menu_restaurants')
            ->where('status', 'facture')
            ->update([
                'regulation_status' => 'not_paid'
            ]);


        DB::unprepared('DROP TRIGGER IF EXISTS before_insert_orders_regulation_status');
        DB::unprepared('DROP TRIGGER IF EXISTS before_update_orders_regulation_status');

        // Trigger à l'insertion
        DB::unprepared('
            CREATE TRIGGER before_insert_orders_regulation_status
            BEFORE INSERT ON orders_menu_restaurants
            FOR EACH ROW
            BEGIN
                IF NEW.status = \'facture\' THEN
                    SET NEW.regulation_status = \'not_paid\';
                END IF;
            END
        ');

        // Trigger à la mise à jour
        DB::unprepared('
            CREATE TRIGGER before_update_orders_regulation_status
            BEFORE UPDATE ON orders_menu_restaurants
            FOR EACH ROW
            BEGIN
                IF NEW.status = \'facture\' THEN
                    SET NEW.regulation_status = \'not_paid\';
                END IF;
            END
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 1. Suppression des triggers actuels
        DB::unprepared('DROP TRIGGER IF EXISTS before_insert_orders_regulation_status');
        DB::unprepared('DROP TRIGGER IF EXISTS before_update_orders_regulation_status');

        DB::table('orders_menu_restaurants')
            ->where('status', 'facture')
            ->where('regulation_status', 'not_paid')
            ->update([
                'regulation_status' => 'facture'
            ]);
    }
};
