<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 🔥 1. Drop FK si elle existe (SAFE)
        $foreignKeys = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_NAME = 'drinks_virtuals_temp'
            AND COLUMN_NAME = 'order_menu_restaurant_uuid'
            AND CONSTRAINT_SCHEMA = DATABASE()
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ");

        foreach ($foreignKeys as $fk) {
            DB::statement("
                ALTER TABLE drinks_virtuals_temp
                DROP FOREIGN KEY {$fk->CONSTRAINT_NAME}
            ");
        }

        // 🔥 2. Drop colonne si existe
        if (Schema::hasColumn('drinks_virtuals_temp', 'order_menu_restaurant_uuid')) {
            Schema::table('drinks_virtuals_temp', function (Blueprint $table) {
                $table->dropColumn('order_menu_restaurant_uuid');
            });
        }

        // 🔥 3. Recreate proprement
        Schema::table('drinks_virtuals_temp', function (Blueprint $table) {

            $table->uuid('order_menu_restaurant_uuid')
                ->nullable()
                ->after('uuid');

            $table->foreign('order_menu_restaurant_uuid')
                ->references('uuid')
                ->on('orders_menu_restaurants')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // 🔥 rollback safe FK
        $foreignKeys = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_NAME = 'drinks_virtuals_temp'
            AND COLUMN_NAME = 'order_menu_restaurant_uuid'
            AND CONSTRAINT_SCHEMA = DATABASE()
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ");

        foreach ($foreignKeys as $fk) {
            DB::statement("
                ALTER TABLE drinks_virtuals_temp
                DROP FOREIGN KEY {$fk->CONSTRAINT_NAME}
            ");
        }

        Schema::table('drinks_virtuals_temp', function (Blueprint $table) {
            if (Schema::hasColumn('drinks_virtuals_temp', 'order_menu_restaurant_uuid')) {
                $table->dropColumn('order_menu_restaurant_uuid');
            }
        });
    }
};
