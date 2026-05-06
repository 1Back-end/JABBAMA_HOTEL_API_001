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
        // 🔹 supprimer la FK si elle existe (safe)
        DB::statement("
        SET @fk_name = (
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_NAME = 'order_menu_item_statuses'
            AND COLUMN_NAME = 'order_menu_restaurant_uuid'
            AND REFERENCED_TABLE_NAME IS NOT NULL
            LIMIT 1
        );
    ");

        DB::statement("
        SET @sql = IF(@fk_name IS NOT NULL,
            CONCAT('ALTER TABLE order_menu_item_statuses DROP FOREIGN KEY ', @fk_name),
            'SELECT 1'
        );
    ");

        DB::statement("PREPARE stmt FROM @sql");
        DB::statement("EXECUTE stmt");
        DB::statement("DEALLOCATE PREPARE stmt");

        // 🔹 recréer la FK
        Schema::table('order_menu_item_statuses', function (Blueprint $table) {
            $table->foreign('order_menu_restaurant_uuid')
                ->references('uuid')
                ->on('orders_menu_restaurants')
                ->onDelete('cascade');
        });
    }
};
