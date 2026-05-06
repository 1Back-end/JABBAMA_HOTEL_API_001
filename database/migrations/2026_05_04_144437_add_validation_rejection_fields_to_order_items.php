<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 🔥 1. DETECTER LA FK REELLE
        $fks = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_NAME = 'orders_menu_restaurant_items'
            AND COLUMN_NAME = 'rejected_after_validation_by'
            AND CONSTRAINT_SCHEMA = DATABASE()
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ");

        // 🔥 2. DROP SAFE FK
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) use ($fks) {

            foreach ($fks as $fk) {
                try {
                    $table->dropForeign($fk->CONSTRAINT_NAME);
                } catch (\Exception $e) {}
            }

            // 🔹 DROP COLUMNS SAFE
            $columns = [
                'rejected_after_validation_by',
                'rejected_after_validation_at',
                'reason_of_rejected_after_validation',
                'is_reason_of_cancel_for_new_update'
            ];

            foreach ($columns as $col) {
                if (Schema::hasColumn('orders_menu_restaurant_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        // 🔥 3. RECREATE COLUMNS
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {

            $table->unsignedBigInteger('rejected_after_validation_by')->nullable();
            $table->timestamp('rejected_after_validation_at')->nullable();
            $table->string('reason_of_rejected_after_validation')->nullable();
            $table->boolean('is_reason_of_cancel_for_new_update')->default(false);
        });

        // 🔥 4. FK PROPRE
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {

            $table->foreign('rejected_after_validation_by', 'omri_rav_by_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {

            try {
                $table->dropForeign('omri_rav_by_fk');
            } catch (\Exception $e) {}

            $columns = [
                'rejected_after_validation_by',
                'rejected_after_validation_at',
                'reason_of_rejected_after_validation',
                'is_reason_of_cancel_for_new_update'
            ];

            foreach ($columns as $col) {
                if (Schema::hasColumn('orders_menu_restaurant_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
