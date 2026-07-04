<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("
            UPDATE client_allocations ca
            JOIN orders_menu_restaurants o ON o.uuid = ca.source_uuid
            SET ca.order_number = o.code
            WHERE ca.source_type = 'order'
        ");
    }

    public function down(): void
    {
        DB::unprepared("
            UPDATE client_allocations
            SET order_number = NULL
            WHERE source_type = 'order'
        ");
    }
};
