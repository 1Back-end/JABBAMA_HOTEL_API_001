<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {

            // 🔥 drop FK si existe
            try {
                $table->dropForeign('fk_rej_after_val_by');
            } catch (\Exception $e) {}

            // 🔥 drop columns si existent
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

        // 🔥 recréation propre
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {

            $table->timestamp('rejected_after_validation_at')->nullable();

            $table->string('reason_of_rejected_after_validation')->nullable();

            $table->boolean('is_reason_of_cancel_for_new_update')->default(false);

            $table->unsignedBigInteger('rejected_after_validation_by')->nullable();

            $table->foreign('rejected_after_validation_by', 'fk_rej_after_val_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {

            try {
                $table->dropForeign('fk_rej_after_val_by');
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
