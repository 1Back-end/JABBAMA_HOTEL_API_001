<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {

            // 🔥 Colonnes (sans after → évite les erreurs)
            if (!Schema::hasColumn('orders_menu_restaurant_items', 'rejected_after_validation_at')) {
                $table->timestamp('rejected_after_validation_at')->nullable();
            }

            if (!Schema::hasColumn('orders_menu_restaurant_items', 'reason_of_rejected_after_validation')) {
                $table->string('reason_of_rejected_after_validation')->nullable();
            }

            if (!Schema::hasColumn('orders_menu_restaurant_items', 'is_reason_of_cancel_for_new_update')) {
                $table->boolean('is_reason_of_cancel_for_new_update')->default(false);
            }

            if (!Schema::hasColumn('orders_menu_restaurant_items', 'rejected_after_validation_by')) {
                $table->unsignedBigInteger('rejected_after_validation_by')->nullable();
            }
        });

        // 🔥 Ajouter FK proprement (séparé pour éviter conflits)
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {

            // Vérifier si FK existe déjà
            $exists = DB::select("
                SELECT CONSTRAINT_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_NAME = 'orders_menu_restaurant_items'
                AND COLUMN_NAME = 'rejected_after_validation_by'
                AND CONSTRAINT_SCHEMA = DATABASE()
            ");

            if (empty($exists)) {
                $table->foreign('rejected_after_validation_by', 'fk_rej_after_val_by')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {

            // 🔥 supprimer FK si existe
            try {
                $table->dropForeign('fk_rej_after_val_by');
            } catch (\Exception $e) {}

            // 🔥 supprimer colonnes si existent
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
