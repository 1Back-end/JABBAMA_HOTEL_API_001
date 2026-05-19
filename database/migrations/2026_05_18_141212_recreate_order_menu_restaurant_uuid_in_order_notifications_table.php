<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 🔥 1. récupérer FK existante dynamiquement
        $fk = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'order_notifications'
              AND COLUMN_NAME = 'order_menu_restaurant_uuid'
              AND REFERENCED_TABLE_NAME IS NOT NULL
            LIMIT 1
        ");

        // 🔥 2. drop FK si existe
        if (!empty($fk)) {
            $fkName = $fk[0]->CONSTRAINT_NAME;

            DB::statement("
                ALTER TABLE order_notifications
                DROP FOREIGN KEY `$fkName`
            ");
        }

        // 🔥 3. drop index si existe
        $index = DB::select("
            SHOW INDEX FROM order_notifications
            WHERE Column_name = 'order_menu_restaurant_uuid'
        ");

        if (!empty($index)) {
            DB::statement("
                DROP INDEX order_notifications_order_menu_restaurant_uuid_index
                ON order_notifications
            ");
        }

        // 🔥 4. drop column si existe
        if (Schema::hasColumn('order_notifications', 'order_menu_restaurant_uuid')) {
            Schema::table('order_notifications', function (Blueprint $table) {
                $table->dropColumn('order_menu_restaurant_uuid');
            });
        }

        // 🔥 5. recréation propre
        Schema::table('order_notifications', function (Blueprint $table) {
            $table->uuid('order_menu_restaurant_uuid')
                ->nullable()
                ->after('uuid');

            $table->index('order_menu_restaurant_uuid');

            $table->foreign('order_menu_restaurant_uuid', 'fk_order_notifications_menu')
                ->references('uuid')
                ->on('orders_menu_restaurants')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('order_notifications', 'order_menu_restaurant_uuid')) {
            Schema::table('order_notifications', function (Blueprint $table) {
                $table->dropForeign(['order_menu_restaurant_uuid']);
                $table->dropColumn('order_menu_restaurant_uuid');
            });
        }
    }
};
