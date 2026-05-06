<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 🔥 récupérer toutes les FK liées à la colonne
        $foreignKeys = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_NAME = 'orders_menu_restaurant_items'
            AND COLUMN_NAME = 'rejected_after_validation_by'
            AND CONSTRAINT_SCHEMA = DATABASE()
        ");

        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) use ($foreignKeys) {

            // 🔥 supprimer toutes les FK existantes
            foreach ($foreignKeys as $fk) {
                $table->dropForeign($fk->CONSTRAINT_NAME);
            }

            // 🔥 ajouter FK propre avec nom court
            $table->foreign('rejected_after_validation_by', 'fk_rej_after_val_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {

            // 🔥 supprimer FK courte
            $table->dropForeign('fk_rej_after_val_by');

            // 🔥 remettre ancienne (optionnel)
            $table->foreign('rejected_after_validation_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }
};
