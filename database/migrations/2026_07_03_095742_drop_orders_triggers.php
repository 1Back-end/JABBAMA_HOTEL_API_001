<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Suppression des triggers existants
        DB::unprepared("
            DROP TRIGGER IF EXISTS before_insert_orders_regulation_status;
        ");

        DB::unprepared("
            DROP TRIGGER IF EXISTS before_update_orders_regulation_status;
        ");
    }

    public function down(): void
    {
        // Recréation des triggers (rollback optionnel)

        DB::unprepared("
            CREATE TRIGGER before_insert_orders_regulation_status
            BEFORE INSERT ON orders_menu_restaurants
            FOR EACH ROW
            BEGIN
                IF NEW.status = 'facture' THEN
                    SET NEW.regulation_status = 'not_paid';
                END IF;
            END
        ");

        DB::unprepared("
            CREATE TRIGGER before_update_orders_regulation_status
            BEFORE UPDATE ON orders_menu_restaurants
            FOR EACH ROW
            BEGIN
                IF NEW.status = 'facture' THEN
                    SET NEW.regulation_status = 'not_paid';
                END IF;
            END
        ");
    }
};
