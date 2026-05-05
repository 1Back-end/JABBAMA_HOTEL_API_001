<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 🔥 récupérer les FK liées à la colonne
        $foreignKeys = DB::select("
        SELECT CONSTRAINT_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_NAME = 'orders_menu_restaurant_items'
        AND COLUMN_NAME = 'rejected_after_validation_by'
        AND CONSTRAINT_SCHEMA = DATABASE()
    ");

        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) use ($foreignKeys) {

            // 🔥 supprimer toutes les FK liées
            foreach ($foreignKeys as $fk) {
                $table->dropForeign($fk->CONSTRAINT_NAME);
            }

            // 🔥 maintenant safe de supprimer
            if (Schema::hasColumn('orders_menu_restaurant_items', 'rejected_after_validation_by')) {
                $table->dropColumn('rejected_after_validation_by');
            }

            if (Schema::hasColumn('orders_menu_restaurant_items', 'rejected_after_validation_at')) {
                $table->dropColumn('rejected_after_validation_at');
            }

            if (Schema::hasColumn('orders_menu_restaurant_items', 'reason_of_rejected_after_validation')) {
                $table->dropColumn('reason_of_rejected_after_validation');
            }

            if (Schema::hasColumn('orders_menu_restaurant_items', 'is_reason_of_cancel_for_new_update')) {
                $table->dropColumn('is_reason_of_cancel_for_new_update');
            }
        });

        // 🔥 recréation propre
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {

            $table->timestamp('rejected_after_validation_at')->nullable();

            $table->string('reason_of_rejected_after_validation')->nullable();

            $table->boolean('is_reason_of_cancel_for_new_update')->default(false);

            $table->unsignedBigInteger('rejected_after_validation_by')->nullable();

            // ✅ nom court contrôlé
            $table->foreign('rejected_after_validation_by', 'fk_rej_after_val_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }
};
