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
        if (!Schema::hasColumn('order_restaurannts_drinks', 'regulation_status')) {
            Schema::table('order_restaurannts_drinks', function (Blueprint $table) {
                $table->string('regulation_status')->default('not_paid');
            });
        }

        DB::table('order_restaurannts_drinks')
            ->where('status', 'paid')
            ->update(['regulation_status' => 'paid']);


        DB::unprepared('DROP TRIGGER IF EXISTS before_insert_drinks_regulation_status');
        DB::unprepared('DROP TRIGGER IF EXISTS before_update_drinks_regulation_status');

        DB::unprepared('
            CREATE TRIGGER before_insert_drinks_regulation_status
            BEFORE INSERT ON order_restaurannts_drinks
            FOR EACH ROW
            BEGIN
                IF NEW.status = \'paid\' THEN
                    SET NEW.regulation_status = \'paid\';
                END IF;
            END
        ');

        // 5. TRIGGER AVANT MODIFICATION
        DB::unprepared('
            CREATE TRIGGER before_update_drinks_regulation_status
            BEFORE UPDATE ON order_restaurannts_drinks
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
        DB::unprepared('DROP TRIGGER IF EXISTS before_insert_drinks_regulation_status');
        DB::unprepared('DROP TRIGGER IF EXISTS before_update_drinks_regulation_status');

        if (Schema::hasColumn('order_restaurannts_drinks', 'regulation_status')) {
            Schema::table('order_restaurannts_drinks', function (Blueprint $table) {
                $table->dropColumn('regulation_status');
            });
        }
    }
};
